<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Jobs\EmitirNfseJob;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\Tomador;
use App\Models\User;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeraPlanilha;
use Tests\TestCase;

/** A empresa (emitente) ativa no menu lateral: notas, importações, tomadores e modelos são só dela. */
class EmpresaAtivaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conta $conta;
    private Emitente $alfa;
    private Emitente $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake([EmitirNfseJob::class]);
        Http::fake(['*' => Http::response([], 503)]);

        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Grupo', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $base = ['codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6,
                'serie_dps' => '00001', 'ambiente' => 2, 'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'x', 'codigo_tributacao_municipal' => '001'];
            $this->alfa = Emitente::create($base + ['cnpj' => '19534920000118', 'razao_social' => 'Alfa Ltda']);
            $this->beta = Emitente::create($base + ['cnpj' => '43559579000130', 'razao_social' => 'Beta Ltda']);
        });
    }

    private function nota(Emitente $e, string $nome): NotaFiscal
    {
        return app(ContaAtual::class)->como($this->conta->id, fn () => NotaFiscal::create([
            'emitente_id' => $e->id, 'tomador_documento' => '52998224725', 'tomador_nome' => $nome, 'serie_dps' => '00001', 'numero_dps' => NotaFiscal::semIsolamento()->count() + 1,
            'ambiente' => 2, 'status' => StatusNota::Rejeitada->value, 'codigo_servico' => '010302', 'descricao' => 'x', 'valor' => 10, 'data_competencia' => '2026-11-05',
        ]));
    }

    private function tomador(Emitente $e, string $doc, string $nome): Tomador
    {
        return app(ContaAtual::class)->como($this->conta->id, fn () => Tomador::create(['emitente_id' => $e->id, 'documento' => $doc, 'nome' => $nome]));
    }

    private function dadosTomador(array $extra = []): array
    {
        return $extra + [
            'documento' => '19566539000130', 'nome' => 'Malix', 'cep' => '01310-100', 'logradouro' => 'Avenida Paulista', 'numero' => '1', 'bairro' => 'Bela Vista',
            'municipio' => 'São Paulo', 'uf' => 'SP', 'codigo_municipio' => '3550308', 'emails' => 'a@b.com',
        ];
    }

    public function test_menu_mostra_a_empresa_ativa_com_select_para_trocar(): void
    {
        $this->actingAs($this->user)->get(route('inicio'))->assertOk()
            ->assertSee('Empresa que você administra')->assertSee('Alfa Ltda')->assertSee('Beta Ltda')
            ->assertSee('19.534.920/0001-18')->assertSee('id="trocar-empresa"', false);
    }

    public function test_notas_e_tomadores_mudam_junto_com_a_empresa_escolhida(): void
    {
        $this->nota($this->alfa, 'Cliente da Alfa');
        $this->nota($this->beta, 'Cliente da Beta');
        $this->tomador($this->alfa, '19566539000130', 'Tomador Alfa SA');
        $this->tomador($this->beta, '07445371000126', 'Tomador Beta SA');

        $this->actingAs($this->user);
        $this->get(route('notas.index'))->assertSee('Cliente da Alfa')->assertDontSee('Cliente da Beta');
        $this->get(route('tomadores.index'))->assertSee('Tomador Alfa SA')->assertDontSee('Tomador Beta SA');

        $this->post(route('emitentes.administrar', $this->beta))->assertRedirect(route('inicio'));

        $this->get(route('notas.index'))->assertSee('Cliente da Beta')->assertDontSee('Cliente da Alfa');
        $this->get(route('tomadores.index'))->assertSee('Tomador Beta SA')->assertDontSee('Tomador Alfa SA');
        $this->assertSame($this->beta->id, $this->user->fresh()->emitente_atual_id);   // lembra a escolha
    }

    public function test_o_mesmo_cpf_cnpj_pode_ser_tomador_em_duas_empresas(): void
    {
        $this->actingAs($this->user);
        $this->post(route('tomadores.store'), $this->dadosTomador())->assertSessionHasNoErrors();
        $this->post(route('tomadores.store'), $this->dadosTomador())->assertSessionHasErrors('documento');   // repetido na mesma empresa

        $this->post(route('emitentes.administrar', $this->beta));
        $this->post(route('tomadores.store'), $this->dadosTomador())->assertSessionHasNoErrors();

        $this->assertSame(2, Tomador::semIsolamento()->where('documento', '19566539000130')->count());
        $this->assertSame([$this->alfa->id, $this->beta->id], Tomador::semIsolamento()->orderBy('id')->pluck('emitente_id')->all());
    }

    public function test_modelos_baixados_sao_sempre_da_empresa_ativa(): void
    {
        $this->actingAs($this->user);
        foreach ([route('tomadores.modelo', ['emitente' => $this->beta->id]), route('importacoes.modelo', ['emitente' => $this->beta->id])] as $url) {
            $r = $this->get($url)->assertOk();
            $arquivo = tempnam(sys_get_temp_dir(), 'mod').'.xlsx';
            file_put_contents($arquivo, $r->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? $r->baseResponse->getFile()->getContent() : $r->streamedContent());
            $this->assertSame('19.534.920/0001-18', \PhpOffice\PhpSpreadsheet\IOFactory::load($arquivo)->getSheet(0)->getCell('A1')->getValue(), 'pediu a Beta, veio a Alfa (ativa)');
        }
    }

    public function test_nao_importa_planilha_de_outra_empresa(): void
    {
        $this->actingAs($this->user);   // empresa ativa: Alfa
        $this->post(route('importacoes.store'), [
            'emitente_id' => $this->beta->id,   // mesmo pedindo a Beta, vale a ativa
            'planilha'    => GeraPlanilha::xlsx($this->beta->cnpj, [['doc' => '52998224725', 'nome_ref' => 'Cliente', 'valor' => 100, 'data' => '2026-11-03']]),
        ])->assertSessionHasErrors('planilha');
        $this->assertSame(0, \App\Models\Importacao::semIsolamento()->count());

        $this->post(route('importacoes.store'), [
            'emitente_id' => $this->alfa->id,
            'planilha'    => GeraPlanilha::xlsx($this->alfa->cnpj, [['doc' => '52998224725', 'nome_ref' => 'Cliente', 'valor' => 100, 'data' => '2026-11-03']]),
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->alfa->id, \App\Models\Importacao::semIsolamento()->firstOrFail()->emitente_id);
    }

    public function test_nota_manual_sai_sempre_pela_empresa_ativa(): void
    {
        $this->actingAs($this->user)->post(route('emitentes.administrar', $this->beta));
        $this->get(route('notas.manual'))->assertOk()->assertSee('Beta Ltda')->assertDontSee('name="emitente_id" tipo="select"', false);
    }

    public function test_empresa_de_outra_conta_nao_pode_ser_administrada(): void
    {
        $outraConta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Outra', 'documento' => '99999999000191', 'email_financeiro' => 'o@x.com']);
        $estranha = app(ContaAtual::class)->como($outraConta->id, fn () => Emitente::create([
            'cnpj' => '11444777000161', 'razao_social' => 'Estranha', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1,
            'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
        ]));

        $this->actingAs($this->user)->post(route('emitentes.administrar', $estranha))->assertNotFound();
        $this->assertNotSame($estranha->id, $this->user->fresh()->emitente_atual_id);
    }

    public function test_migracao_separa_o_tomador_usado_por_duas_empresas_e_reaponta_as_notas(): void
    {
        // situação de antes da mudança: um tomador da conta, sem empresa, com notas de duas empresas
        $t = app(ContaAtual::class)->como($this->conta->id, fn () => Tomador::create(['documento' => '19566539000130', 'nome' => 'Compartilhado']));
        $this->assertNull($t->emitente_id);
        $n1 = $this->nota($this->alfa, 'x');
        $n2 = $this->nota($this->alfa, 'x');
        $n3 = $this->nota($this->beta, 'x');
        NotaFiscal::semIsolamento()->whereIn('id', [$n1->id, $n2->id, $n3->id])->update(['tomador_id' => $t->id]);
        $sem = app(ContaAtual::class)->como($this->conta->id, fn () => Tomador::create(['documento' => '07445371000126', 'nome' => 'Sem notas']));

        (require base_path('database/migrations/2026_11_13_000001_tomadores_por_emitente.php'))->up();

        $this->assertSame($this->alfa->id, $t->fresh()->emitente_id);                       // a empresa que mais usou fica com o original
        $copia = Tomador::semIsolamento()->where('documento', '19566539000130')->where('emitente_id', $this->beta->id)->firstOrFail();
        $this->assertSame($copia->id, $n3->fresh()->tomador_id);                              // a nota da outra empresa aponta para a cópia dela
        $this->assertSame($t->id, $n1->fresh()->tomador_id);
        $this->assertSame($this->alfa->id, $sem->fresh()->emitente_id);                       // sem notas: primeira empresa da conta
    }

    public function test_lista_de_emitentes_marca_a_em_uso_e_oferece_administrar_a_outra(): void
    {
        $this->actingAs($this->user)->get(route('emitentes.index'))->assertOk()
            ->assertSee('em uso')->assertSee(route('emitentes.administrar', $this->beta), false);
    }
}
