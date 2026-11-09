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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Emissão manual: a tela já vem com os padrões da empresa e a nota entra no mesmo fluxo das importadas. */
class NotaManualTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([EmitirNfseJob::class]);

        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
                'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'Hospedagem de dados.', 'codigo_nbs_padrao' => '115090000',
            ]);
        });
    }

    private function dados(array $extra = []): array
    {
        return $extra + [
            'emitente_id' => $this->emitente->id,
            'tomador_documento' => '19566539000130', 'tomador_nome' => 'Malix Automóveis Ltda', 'tomador_email' => 'financeiro@malix.com.br',
            'codigo_servico' => '010302', 'descricao' => 'Hospedagem de dados.', 'codigo_nbs' => '115090000',
            'valor' => '452,50', 'data_competencia' => now()->toDateString(), 'confirmo' => '1',
        ];
    }

    public function test_tela_vem_com_os_padroes_da_empresa_marcados(): void
    {
        $this->actingAs($this->user)->get(route('notas.manual'))->assertOk()
            ->assertSee('Usar os padrões da empresa')
            ->assertSee('buscaServico(', false)->assertSee('010302')
            ->assertSee('Hospedagem de dados.')
            ->assertSee('115090000');
    }

    public function test_emite_com_os_padroes_e_cria_o_cliente(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados())
            ->assertRedirect(route('notas.index'))->assertSessionHasNoErrors();

        $nota = NotaFiscal::firstOrFail();
        $this->assertSame('web', $nota->origem);
        $this->assertNull($nota->importacao_id);
        $this->assertSame('010302', $nota->codigo_servico);
        $this->assertSame('452.50', (string) $nota->valor);
        $this->assertSame(StatusNota::Pendente, $nota->status);   // modo teste: sem custo, vai direto para a fila
        $this->assertNotNull($nota->tomador_id);
        $this->assertSame('Malix Automóveis Ltda', Tomador::firstOrFail()->nome);
        Queue::assertPushed(EmitirNfseJob::class);
    }

    public function test_campos_do_servico_em_branco_caem_nos_padroes_da_empresa(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados(['codigo_servico' => '', 'descricao' => '', 'codigo_nbs' => '']))
            ->assertSessionHasNoErrors();

        $nota = NotaFiscal::firstOrFail();
        $this->assertSame('010302', $nota->codigo_servico);
        $this->assertSame('Hospedagem de dados.', $nota->descricao);
        $this->assertSame('115090000', $nota->codigo_nbs);
    }

    public function test_alterar_o_servico_vale_so_para_esta_nota(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados(['codigo_servico' => '010701', 'descricao' => 'Suporte avulso.']))
            ->assertSessionHasNoErrors();

        $this->assertSame('010701', NotaFiscal::firstOrFail()->codigo_servico);
        $this->assertSame('010302', $this->emitente->fresh()->codigo_servico_padrao);
    }

    public function test_dados_invalidos_voltam_para_a_tela_sem_criar_nada(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados(['valor' => '', 'tomador_documento' => '123', 'codigo_servico' => '999999']))
            ->assertSessionHasErrors(['valor', 'tomador_documento', 'codigo_servico']);

        $this->assertSame(0, NotaFiscal::count());
        $this->assertSame(0, Tomador::count());
    }

    public function test_sem_destinatario_nao_exige_cliente(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados(['sem_tomador' => '1', 'tomador_documento' => '', 'tomador_nome' => '']))
            ->assertSessionHasNoErrors();

        $nota = NotaFiscal::firstOrFail();
        $this->assertTrue($nota->sem_tomador);
        $this->assertNull($nota->tomador_id);
    }

    public function test_cobranca_parecida_pede_confirmacao(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados());
        NotaFiscal::query()->update(['status' => StatusNota::Emitida, 'numero_nfse' => '9']);

        $this->actingAs($this->user)->post(route('notas.manual.conferir'), $this->dados())
            ->assertOk()->assertSee('já existe uma nota parecida')->assertSee('É outra cobrança');
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados())
            ->assertRedirect(route('notas.manual'))->assertSessionHas('duplicidade');
        $this->assertSame(1, NotaFiscal::count());

        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados(['confirmar_duplicidade' => '1']))
            ->assertRedirect(route('notas.index'));
        $this->assertSame(2, NotaFiscal::count());
    }

    public function test_conferencia_mostra_o_resumo_e_nao_grava_nada(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.conferir'), $this->dados())
            ->assertOk()
            ->assertSee('Conferir antes de emitir')
            ->assertSee('Malix Automóveis Ltda')
            ->assertSee('R$ 452,50')
            ->assertSee('padrão da empresa')
            ->assertSee('Armazenamento ou hospedagem de dados')
            ->assertSee('Conferi os dados e confirmo a emissão');

        $this->assertSame(0, NotaFiscal::count());
        $this->assertSame(0, Tomador::count());
        Queue::assertNothingPushed();
    }

    public function test_conferencia_com_dados_invalidos_volta_ao_formulario(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.conferir'), $this->dados(['valor' => '0']))
            ->assertRedirect(route('notas.manual'))->assertSessionHasErrors('valor');
    }

    public function test_sem_o_confirmo_a_nota_nao_e_emitida(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados(['confirmo' => '']))
            ->assertRedirect(route('notas.manual'))->assertSessionHasErrors('confirmo');

        $this->assertSame(0, NotaFiscal::count());
        Queue::assertNothingPushed();
    }

    public function test_alterar_dados_volta_com_tudo_preenchido(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.voltar'), $this->dados())
            ->assertRedirect(route('notas.manual'));

        $this->actingAs($this->user)->get(route('notas.manual'))->assertSee('financeiro@malix.com.br')->assertSee('452,50');
    }

    public function test_busca_de_clientes_cadastrados(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados());

        $this->actingAs($this->user)->getJson(route('tomadores.buscar', ['q' => 'malix']))
            ->assertOk()->assertJsonFragment(['documento' => '19566539000130', 'email' => 'financeiro@malix.com.br']);
        $this->actingAs($this->user)->getJson(route('tomadores.buscar', ['q' => '1956653']))->assertJsonCount(1);
        $this->actingAs($this->user)->getJson(route('tomadores.buscar', ['q' => 'x']))->assertExactJson([]);
    }

    public function test_nota_manual_recusada_pode_ser_corrigida(): void
    {
        $this->actingAs($this->user)->post(route('notas.manual.store'), $this->dados());
        $nota = NotaFiscal::firstOrFail();
        $nota->update(['status' => StatusNota::Rejeitada, 'erros' => ['E0310']]);

        $this->actingAs($this->user)->get(route('pendencias', ['nota' => $nota->id]))->assertOk()->assertSee('Nota manual #'.$nota->id);
        $this->actingAs($this->user)->patch(route('notas.corrigir', $nota), ['codigo_servico' => '010701'])->assertSessionHasNoErrors();
        $this->assertSame('010701', $nota->fresh()->codigo_servico);
    }
}
