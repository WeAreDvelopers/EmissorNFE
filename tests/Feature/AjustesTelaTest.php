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
use App\Models\User;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Ajustes pedidos no ajustes.pdf: excluir, planilha fora do padrão, correção rápida (AJAX), logo da empresa. */
class AjustesTelaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conta $conta;
    private Emitente $emitente;

    private const CAB = 'tomador_documento;tomador_nome;tomador_email;codigo_servico;codigo_nbs;descricao;valor;data_competencia';

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
    }

    private function enviar(string $nome, string $conteudo)
    {
        return $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id,
            'planilha'    => UploadedFile::fake()->createWithContent($nome, $conteudo),
        ]);
    }

    private function dataFutura(): string
    {
        return now()->addDays(10)->format('d/m/Y');
    }

    /** Lote com N linhas válidas e M com data de competência no futuro (inválidas). */
    private function lote(int $validas, int $invalidas = 0): Importacao
    {
        $linhas = [self::CAB];
        $docs = ['52998224725', '11444777000161', '11222333000181', '39053344705'];
        for ($i = 0; $i < $validas; $i++) {
            $linhas[] = $docs[$i % 4].";Cliente $i;;010701;;Serviço;100,00;".now()->subDay()->format('d/m/Y');
        }
        for ($i = 0; $i < $invalidas; $i++) {
            $linhas[] = $docs[$i % 4].";Futuro $i;;010701;;Serviço;100,00;".$this->dataFutura();
        }
        $this->enviar('lote.csv', implode("\n", $linhas)."\n")->assertRedirect();

        return Importacao::latest('id')->firstOrFail();
    }

    // ---------- arquivo fora do padrão ----------

    public function test_arquivo_txt_e_barrado(): void
    {
        $this->enviar('premissas.txt', self::CAB."\n")->assertSessionHasErrors('planilha');

        $this->assertSame(0, Importacao::count());
        $this->get(route('importacoes.criar'))->assertOk();
    }

    public function test_planilha_sem_as_colunas_do_modelo_e_barrada_e_diz_quais_faltam(): void
    {
        $this->enviar('outra.csv', "nome;valor\nJoão;10\n")
            ->assertSessionHasErrors('planilha');

        $erro = session('errors')->first('planilha');
        $this->assertStringContainsString('fora do padrão', $erro);
        $this->assertStringContainsString('tomador_documento', $erro);
        $this->assertStringContainsString('data_competencia', $erro);
        $this->assertSame(0, Importacao::count());
    }

    public function test_coluna_codigo_servico_pode_faltar_se_a_empresa_tem_codigo_padrao(): void
    {
        $linha = '11222333000181;Cliente;;Serviço;100,00;'.now()->subDay()->format('d/m/Y');
        $cab = 'tomador_documento;tomador_nome;tomador_email;descricao;valor;data_competencia';

        $this->enviar('sem-codigo.csv', "$cab\n$linha\n")->assertSessionHasErrors('planilha');

        $this->emitente->update(['codigo_servico_padrao' => '010701']);
        Importacao::query()->delete();
        $this->enviar('sem-codigo.csv', "$cab\n$linha\n")->assertSessionHasNoErrors();
    }

    public function test_tela_de_importacao_mostra_o_alerta_forte_com_o_motivo(): void
    {
        $this->enviar('premissas.txt', 'x')->assertSessionHasErrors('planilha');

        $this->get(route('importacoes.criar'))->assertOk()
            ->assertSee('alerta-forte', false)->assertSee('Não foi possível usar este arquivo')->assertSee('fora do padrão');
    }

    // ---------- excluir ----------

    public function test_excluir_linha_do_lote_antes_do_envio(): void
    {
        $imp = $this->lote(2, 1);
        $nota = $imp->notas()->where('status', StatusNota::Invalida)->firstOrFail();

        $this->deleteJson(route('notas.excluir', $nota))->assertOk()->assertJson(['ok' => true]);

        $this->assertNull(NotaFiscal::find($nota->id));
        $this->assertSame(2, $imp->fresh()->notas()->count());
        $this->assertSame(0, $imp->fresh()->linhas_invalidas);
    }

    public function test_nao_exclui_linha_de_lote_ja_enviado(): void
    {
        $imp = $this->lote(1);
        $nota = $imp->notas()->firstOrFail();
        $imp->update(['status' => 'enviada']);

        $this->deleteJson(route('notas.excluir', $nota))->assertStatus(422);
        $this->assertNotNull(NotaFiscal::find($nota->id));
    }

    public function test_excluir_importacao_apaga_notas_e_arquivo(): void
    {
        $imp = $this->lote(2, 1);
        $caminho = $imp->arquivo_caminho;
        Storage::disk('local')->assertExists($caminho);

        $this->deleteJson(route('importacoes.excluir', $imp))->assertOk()->assertJson(['ok' => true]);

        $this->assertNull(Importacao::find($imp->id));
        $this->assertSame(0, NotaFiscal::semIsolamento()->count());
        Storage::disk('local')->assertMissing($caminho);
    }

    public function test_nao_exclui_importacao_com_nota_emitida_ou_na_fila(): void
    {
        $imp = $this->lote(2);
        $imp->notas()->first()->update(['status' => StatusNota::Emitida]);

        $this->deleteJson(route('importacoes.excluir', $imp))->assertStatus(422);
        $this->assertNotNull(Importacao::find($imp->id));
    }

    public function test_excluir_importacao_ja_enviada_sem_nada_emitido(): void
    {
        $imp = $this->lote(1);
        $imp->notas()->update(['status' => StatusNota::Rejeitada]);
        $imp->update(['status' => 'enviada']);

        $this->deleteJson(route('importacoes.excluir', $imp))->assertOk();
        $this->assertNull(Importacao::find($imp->id));
    }

    public function test_botao_de_excluir_aparece_na_lista(): void
    {
        $imp = $this->lote(1);

        $this->get(route('importacoes.index'))->assertOk()
            ->assertSee(route('importacoes.excluir', $imp), false)->assertSee('data-confirmar', false);
    }

    // ---------- correção rápida (AJAX) ----------

    public function test_corrigir_por_ajax_devolve_json_e_deixa_a_linha_pronta(): void
    {
        $imp = $this->lote(1, 1);
        $nota = $imp->notas()->where('status', StatusNota::Invalida)->firstOrFail();

        $this->patchJson(route('notas.corrigir', $nota), ['data_competencia' => now()->subDay()->format('d/m/Y')])
            ->assertOk()->assertJson(['ok' => true])->assertJsonStructure(['mensagem', 'notas']);

        $this->assertSame(StatusNota::Rascunho, $nota->fresh()->status);
    }

    public function test_corrigir_por_ajax_com_erro_devolve_422_por_campo(): void
    {
        $imp = $this->lote(1, 1);
        $nota = $imp->notas()->where('status', StatusNota::Invalida)->firstOrFail();

        $this->patchJson(route('notas.corrigir', $nota), ['data_competencia' => $this->dataFutura()])
            ->assertStatus(422)->assertJsonValidationErrors('data_competencia');
    }

    public function test_aplicar_a_correcao_nas_outras_linhas_com_o_mesmo_problema(): void
    {
        $imp = $this->lote(1, 3);   // 3 linhas com a mesma data futura
        $primeira = $imp->notas()->where('status', StatusNota::Invalida)->orderBy('id')->firstOrFail();

        $this->patchJson(route('notas.corrigir', $primeira), [
            'data_competencia' => now()->subDay()->format('d/m/Y'),
            'aplicar_em_todas' => 1, 'campo_aplicar' => 'data_competencia',
        ])->assertOk();

        $this->assertSame(0, $imp->notas()->where('status', StatusNota::Invalida)->count());
        $this->assertSame(4, $imp->notas()->where('status', StatusNota::Rascunho)->count());
        $this->assertSame(0, $imp->fresh()->linhas_invalidas);
    }

    public function test_telas_trazem_as_regioes_que_o_ajax_atualiza(): void
    {
        $imp = $this->lote(1, 1);

        $this->get(route('importacoes.conferir', $imp))->assertOk()
            ->assertSee('data-lista="corrija"', false)->assertSee('data-regiao="resumo"', false)
            ->assertSee('data-regiao="envio"', false)->assertSee('data-ajax', false)->assertSee('Excluir');
    }

    // ---------- logo da empresa ----------

    private function dadosEmpresa(array $extra = []): array
    {
        return $extra + [
            'cnpj' => '00000000000191', 'razao_social' => 'Padaria Teste', 'codigo_municipio' => '3549904',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6,
            'serie_dps' => '00001', 'ambiente' => 2,
        ];
    }

    public function test_logo_da_empresa_e_guardada_e_aparece_na_importacao(): void
    {
        $this->actingAs($this->user)->put(route('emitentes.update', $this->emitente), $this->dadosEmpresa([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]))->assertSessionHasNoErrors();

        $emitente = $this->emitente->fresh();
        $this->assertNotNull($emitente->logo_arquivo);
        Storage::disk('public')->assertExists($emitente->logo_arquivo);
        $this->assertStringContainsString('storage/'.$emitente->logo_arquivo, $emitente->logo_url);

        // na tela de importação a URL vai dentro de JSON (barras escapadas): confere pelo nome do arquivo
        $this->get(route('importacoes.criar'))->assertOk()->assertSee(basename($emitente->logo_arquivo), false);

        $imp = $this->lote(1);
        $this->get(route('importacoes.conferir', $imp))->assertOk()->assertSee($emitente->logo_url, false);
    }

    public function test_logo_invalida_e_trocada_ou_removida(): void
    {
        $this->actingAs($this->user)->put(route('emitentes.update', $this->emitente), $this->dadosEmpresa([
            'logo' => UploadedFile::fake()->create('script.svg', 5, 'image/svg+xml'),
        ]))->assertSessionHasErrors('logo');

        $this->put(route('emitentes.update', $this->emitente), $this->dadosEmpresa(['logo' => UploadedFile::fake()->image('a.png')]));
        $antiga = $this->emitente->fresh()->logo_arquivo;

        $this->put(route('emitentes.update', $this->emitente), $this->dadosEmpresa(['logo' => UploadedFile::fake()->image('b.jpg')]));
        $nova = $this->emitente->fresh()->logo_arquivo;
        $this->assertNotSame($antiga, $nova);
        Storage::disk('public')->assertMissing($antiga);

        $this->put(route('emitentes.update', $this->emitente), $this->dadosEmpresa(['remover_logo' => 1]));
        $this->assertNull($this->emitente->fresh()->logo_arquivo);
        Storage::disk('public')->assertMissing($nova);
    }

    public function test_edicao_da_empresa_mostra_o_certificado_ja_enviado_em_destaque(): void
    {
        $this->emitente->forceFill([
            'certificado_arquivo' => 'certificados/emitente-1.pfx.enc', 'certificado_validade' => now()->addMonths(6),
        ])->save();

        $this->actingAs($this->user)->get(route('emitentes.editar', $this->emitente))->assertOk()
            ->assertSee('Certificado já enviado')->assertSee('Não precisa enviar de novo');
    }
}
