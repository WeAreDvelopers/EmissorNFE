<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Jobs\EmitirNfseJob;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\Tomador;
use App\Models\User;
use App\Support\CatalogoErros;
use App\Support\ExplicacaoErro;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeraPlanilha;
use Tests\TestCase;

/** Orientação para corrigir cada erro da prefeitura: catálogo, painel "Precisa de você", reenvio e reenvio em lote. */
class CatalogoErrosTest extends TestCase
{
    use RefreshDatabase;

    private const E0116 = 'E0116 A IM deve ser informada para o emitente prestador do serviço na DPS, conforme informações complementares registradas no CNC NFS-e do município emissor informado na DPS.';
    private const ACOES = ['nota', 'emitente', 'cliente', 'contador', 'sistema', 'nao_suportado'];

    private User $user;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake([EmitirNfseJob::class]);

        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Empresa Teste', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '00000000000191', 'razao_social' => 'Padaria Teste', 'codigo_municipio' => '3549904',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
            ]);
        });
        $this->user->forceFill(['emitente_atual_id' => $this->emitente->id])->save();
    }

    /** Lote com N notas válidas, clientes com cadastro completo (para o reenvio não parar no cadastro). */
    private function lote(int $qtd): Importacao
    {
        $docs = ['52998224725', '11444777000161', '11222333000181', '39053344705'];
        $linhas = [];
        for ($i = 0; $i < $qtd; $i++) {
            $linhas[] = ['doc' => $docs[$i % 4], 'nome_ref' => "Cliente $i", 'codigo' => '010701', 'descricao' => 'Serviço', 'valor' => 100];
        }
        $this->actingAs($this->user)->post(route('importacoes.store'), ['emitente_id' => $this->emitente->id, 'planilha' => GeraPlanilha::xlsx($this->emitente->cnpj, $linhas)])->assertRedirect();
        app(ContaAtual::class)->como($this->conta->id, function () use ($docs, $qtd) {
            foreach (array_slice($docs, 0, min($qtd, 4)) as $i => $doc) {
                Tomador::create([
                    'conta_id' => $this->conta->id, 'emitente_id' => $this->emitente->id, 'documento' => $doc, 'nome' => "Cliente $i",
                    'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'cep' => '01001000', 'municipio' => 'São Paulo', 'uf' => 'SP',
                    'codigo_municipio' => '3550308', 'emails' => ['c@x.com'], 'pendente' => false,
                ]);
            }
        });

        return Importacao::latest('id')->firstOrFail();
    }

    private function recusar(NotaFiscal $nota, string ...$erros): NotaFiscal
    {
        $nota->update(['status' => StatusNota::Rejeitada, 'erros' => $erros]);

        return $nota->fresh();
    }

    // ---------- catálogo ----------

    public function test_todo_codigo_do_anexo_tem_acao_valida_e_orientacao(): void
    {
        $codigos = CatalogoErros::todos();
        $this->assertGreaterThan(400, count($codigos));

        foreach ($codigos as $codigo) {
            $e = CatalogoErros::entrada($codigo);
            $this->assertNotNull($e, $codigo);
            $this->assertContains($e['acao'], self::ACOES, $codigo);
            $this->assertNotSame('', trim((string) ($e['titulo'] ?? $e['orientacao'] ?? '')), "{$codigo} sem orientação");
        }
    }

    public function test_textos_proprios_apontam_para_campos_que_existem_na_tela_de_destino(): void
    {
        $emitenteTela = file_get_contents(resource_path('views/emitentes/form.blade.php'));
        $tomadorTela = file_get_contents(resource_path('views/tomadores/_campos.blade.php'));
        $existe = fn (string $tela, string $campo) => (bool) preg_match('/(?:id|name|nome)="'.preg_quote($campo, '/').'"/', $tela);

        $proprios = require resource_path('data/erros-nfse.php');
        foreach ($proprios as $codigo => $e) {
            $this->assertContains($e['acao'], self::ACOES, $codigo);
            foreach (['titulo', 'aconteceu', 'como_resolver'] as $chave) {
                $this->assertNotSame('', trim((string) ($e[$chave] ?? '')), "{$codigo} sem {$chave}");
            }
            $campo = $e['campo'] ?? '';
            if ($campo === '') {
                continue;
            }
            match ($e['acao']) {
                'emitente' => $this->assertTrue($existe($emitenteTela, $campo), "{$codigo}: campo {$campo} não existe no cadastro da empresa"),
                'contador' => $this->assertTrue($existe($emitenteTela, $campo) || isset(ExplicacaoErro::ROTULOS[$campo]), "{$codigo}: campo {$campo} não existe na tela de destino"),
                'cliente' => $this->assertTrue($existe($tomadorTela, $campo), "{$codigo}: campo {$campo} não existe no cadastro do cliente"),
                'nota' => $this->assertArrayHasKey($campo, ExplicacaoErro::ROTULOS, "{$codigo}: campo {$campo} não é editável na nota"),
                default => null,
            };
        }
    }

    public function test_erro_do_catalogo_vira_orientacao_com_destino_certo(): void
    {
        $nota = $this->recusar($this->lote(1)->notas()->firstOrFail(), self::E0116);

        $ex = ExplicacaoErro::para($nota);

        $this->assertSame('Falta a inscrição municipal da empresa', $ex['titulo']);
        $this->assertSame('emitente', $ex['acao']);
        $this->assertSame('Ajuste no cadastro da empresa', $ex['rotulo']);
        $this->assertSame(['E0116'], $ex['codigos']);
        $this->assertSame(route('emitentes.editar', $this->emitente).'?destacar=inscricao_municipal', $ex['destino']['url']);
        $this->assertTrue($ex['reenviar_direto']);
        $this->assertSame([], $ex['campos']);
        $this->assertStringContainsString('CCM', $ex['contador']);
    }

    public function test_codigo_sem_texto_proprio_usa_a_orientacao_base_e_nunca_o_generico(): void
    {
        // E0595 tem texto próprio; E0446 (valor da dedução) só tem a base
        $nota = $this->recusar($this->lote(1)->notas()->firstOrFail(), 'E0446 Valor da dedução inválido.');

        $ex = ExplicacaoErro::para($nota);

        $this->assertSame('emitente', $ex['acao']);
        $this->assertNotNull($ex['destino']);
        $this->assertStringNotContainsString('Veja o motivo em "Detalhes técnicos"', $ex['o_que_aconteceu']);
    }

    public function test_erro_de_campo_da_nota_abre_so_o_campo_certo(): void
    {
        $nota = $this->recusar($this->lote(1)->notas()->firstOrFail(), 'E0015 A data de competência não pode ser posterior à data de emissão.');

        $ex = ExplicacaoErro::para($nota);

        $this->assertSame('nota', $ex['acao']);
        $this->assertSame(['data_competencia'], $ex['campos']);
        $this->assertFalse($ex['reenviar_direto']);
    }

    public function test_erro_do_prestador_nao_e_mais_confundido_com_o_do_cliente(): void
    {
        // E0080 é o CNPJ do prestador (empresa); o do cliente é o E0188
        $nota = $this->recusar($this->lote(1)->notas()->firstOrFail(), 'E0080 CNPJ do prestador informado na DPS é inválido.');
        $this->assertSame('emitente', ExplicacaoErro::para($nota)['acao']);

        $nota = $this->recusar($nota, 'E0188 CNPJ do tomador informado na DPS é inválido.');
        $this->assertSame(['tomador_documento'], ExplicacaoErro::para($nota)['campos']);
    }

    // ---------- tela e reenvio ----------

    public function test_painel_mostra_o_botao_do_cadastro_a_mensagem_ao_contador_e_o_reenvio(): void
    {
        $nota = $this->recusar($this->lote(1)->notas()->firstOrFail(), self::E0116);

        $this->actingAs($this->user)->get(route('pendencias', ['nota' => $nota->id]))->assertOk()
            ->assertSee('Falta a inscrição municipal da empresa')
            ->assertSee('Abrir o cadastro da empresa')
            ->assertSee('Copiar mensagem para o contador')
            ->assertSee('Reenviar')
            ->assertSee('Ajuste no cadastro da empresa')
            ->assertSee('?destacar=inscricao_municipal', false);
    }

    public function test_nota_recusada_por_erro_de_cadastro_pode_ser_reenviada_mas_erro_de_campo_nao(): void
    {
        $notas = $this->lote(2)->notas()->get();
        $cadastro = $this->recusar($notas[0], self::E0116);
        $campo = $this->recusar($notas[1], 'E0015 A data de competência não pode ser posterior à data de emissão.');

        $this->actingAs($this->user)->post(route('notas.reenviar', $campo))->assertStatus(422);
        $this->assertSame(StatusNota::Rejeitada, $campo->fresh()->status);

        $this->post(route('notas.reenviar', $cadastro))->assertRedirect();
        $this->assertNotSame(StatusNota::Rejeitada, $cadastro->fresh()->status);
        Queue::assertPushed(EmitirNfseJob::class);
    }

    public function test_reenvio_em_lote_das_notas_paradas_pelo_mesmo_erro(): void
    {
        $notas = $this->lote(3)->notas()->get();
        $this->recusar($notas[0], self::E0116);
        $this->recusar($notas[1], self::E0116);
        $this->recusar($notas[2], 'E0015 A data de competência não pode ser posterior à data de emissão.');

        $this->actingAs($this->user)->get(route('pendencias'))->assertOk()
            ->assertSee('2 notas')->assertSee('Reenviar as 2');

        $this->post(route('pendencias.reenviar-lote'), ['codigo' => 'E0116'])->assertRedirect()->assertSessionHas('ok', '2 notas reenviadas.');

        $this->assertSame(StatusNota::Rejeitada, $notas[2]->fresh()->status);   // a de data continua esperando correção
        $this->assertNotSame(StatusNota::Rejeitada, $notas[0]->fresh()->status);
        $this->assertNotSame(StatusNota::Rejeitada, $notas[1]->fresh()->status);

        $this->post(route('pendencias.reenviar-lote'), ['codigo' => 'abc'])->assertStatus(422);
    }

    public function test_falha_do_sistema_no_xsd_entra_no_lote_como_xsd(): void
    {
        $notas = $this->lote(2)->notas()->get();
        foreach ($notas as $n) {
            $this->recusar($n, "XSD: Element 'DPS', attribute 'versao': [facet 'maxLength'] The value 'Versão DPS' has a length of '10'.");
        }

        $this->actingAs($this->user)->get(route('pendencias'))->assertSee('Reenviar as 2');
        $this->post(route('pendencias.reenviar-lote'), ['codigo' => 'XSD'])->assertSessionHas('ok', '2 notas reenviadas.');
    }

    // ---------- telemetria ----------

    public function test_registra_os_codigos_vistos_e_marca_os_sem_traducao(): void
    {
        CatalogoErros::registrar([self::E0116, 'E9999 Erro novo que ninguém conhece'], 7);
        CatalogoErros::registrar([self::E0116], 8);

        $this->assertSame(2, (int) DB::table('erros_nfse_vistos')->where('codigo', 'E0116')->value('ocorrencias'));
        $this->assertFalse((bool) DB::table('erros_nfse_vistos')->where('codigo', 'E0116')->value('sem_traducao'));
        $this->assertSame(8, (int) DB::table('erros_nfse_vistos')->where('codigo', 'E0116')->value('ultima_nota_id'));
        $this->assertTrue((bool) DB::table('erros_nfse_vistos')->where('codigo', 'E9999')->value('sem_traducao'));
        $this->assertSame(['E9999'], CatalogoErros::desconhecidos([self::E0116, 'E9999 novo']));
    }
}
