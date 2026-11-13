<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Jobs\EmitirNfseJob;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\Revenda;
use App\Models\User;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeraPlanilha;
use Tests\TestCase;

/** Prévia da importação: todas as colunas, contagem de corretas x com problema e cada valor errado em vermelho com o motivo. */
class PreviaImportacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake([EmitirNfseJob::class]);

        $this->user = User::factory()->create();
        $conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $conta->id])->save();
        app(ContaAtual::class)->como($conta->id, fn () => $this->emitente = Emitente::create([
            'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1,
            'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2, 'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'Suporte',
        ]));
    }

    public function test_planilha_de_notas_aceita_ate_50_linhas_e_recusa_mais_que_isso(): void
    {
        $linha = fn ($i) => ['doc' => '52998224725', 'nome_ref' => "Cliente $i", 'valor' => 10 + $i, 'data' => '2026-11-03'];
        $enviar = fn (int $n) => $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id,
            'planilha'    => GeraPlanilha::xlsx($this->emitente->cnpj, array_map($linha, range(1, $n))),
        ]);

        $enviar(51)->assertSessionHasErrors('planilha');
        $this->assertStringContainsString('limite é de 50', session('errors')->first('planilha'));
        $this->assertSame(0, Importacao::count());

        $enviar(50)->assertSessionHasNoErrors();
        $this->assertSame(50, Importacao::firstOrFail()->notas()->count());
    }

    public function test_previa_mostra_todas_as_colunas_conta_as_corretas_e_destaca_o_que_esta_errado(): void
    {
        $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id,
            'planilha'    => GeraPlanilha::xlsx($this->emitente->cnpj, [
                ['doc' => '52998224725', 'nome_ref' => 'Cliente Certo', 'valor' => 100, 'data' => '2026-11-03', 'emails' => 'certo@x.com'],
                ['doc' => '52998224726', 'nome_ref' => 'Cliente Errado', 'valor' => 250, 'data' => '2026-11-03', 'emails' => 'sem-arroba'],   // CPF com dígito errado e e-mail ruim
            ]),
        ])->assertSessionHasNoErrors();
        $imp = Importacao::latest('id')->firstOrFail();

        $this->assertSame(1, $imp->notas()->where('status', StatusNota::Rascunho)->count());
        $this->assertSame(1, $imp->notas()->where('status', StatusNota::Invalida)->count());

        $r = $this->get(route('importacoes.conferir', $imp))->assertOk();
        $r->assertSee('linha correta')->assertSee('precisa de correção')                       // contagem
            ->assertSee('Cliente Certo')->assertSee('Cliente Errado')                           // lista completa
            ->assertSee('CPF/CNPJ')->assertSee('E-mail')->assertSee('NBS')                      // todas as colunas
            ->assertSee('certo@x.com')->assertSee('529.982.247-25')                                       // dados de cada linha
            ->assertSee('O CPF/CNPJ do cliente não é válido')                                   // tooltip do campo errado
            ->assertSee('O e-mail do cliente não parece válido')
            ->assertSee('border-perigo-200', false);                                            // vermelho no valor errado
    }
}
