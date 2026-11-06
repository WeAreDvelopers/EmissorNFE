<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Jobs\EmitirNfseJob;
use App\Models\Carteira;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\MovimentoCredito;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Services\Creditos\CarteiraService;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeraPlanilha;
use Tests\TestCase;

/** Modo teste (homologação) não consome crédito; produção continua reservando e cobrando. */
class CreditosHomologacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake([EmitirNfseJob::class]);

        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Empresa Teste', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();
    }

    private function emitente(int $ambiente): void
    {
        app(ContaAtual::class)->como($this->conta->id, function () use ($ambiente) {
            $this->emitente = Emitente::create([
                'cnpj' => '00000000000191', 'razao_social' => 'Padaria Teste', 'codigo_municipio' => '3549904',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => $ambiente,
            ]);
        });
    }

    /** Importa 2 linhas válidas e envia, SEM nenhum crédito na carteira. */
    private function enviarSemSaldo(): Importacao
    {
        $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id,
            'planilha'    => GeraPlanilha::xlsx($this->emitente->cnpj, [
                ['doc' => '52998224725', 'nome_ref' => 'Cliente 0', 'codigo' => '010302', 'descricao' => 'Serviço 0', 'valor' => 100],
                ['doc' => '11444777000161', 'nome_ref' => 'Cliente 1', 'codigo' => '010302', 'descricao' => 'Serviço 1', 'valor' => 100],
            ]),
        ])->assertRedirect();

        $imp = Importacao::firstOrFail();
        $this->post(route('importacoes.enviar', $imp), ['modo' => 'enviar_prontas']);

        return $imp->fresh();
    }

    private function contar(StatusNota $s): int
    {
        return NotaFiscal::semIsolamento()->where('status', $s)->count();
    }

    public function test_em_homologacao_envia_sem_saldo_e_nao_mexe_na_carteira(): void
    {
        $this->emitente(2);
        $this->enviarSemSaldo();

        $this->assertSame(2, $this->contar(StatusNota::Pendente));
        $this->assertSame(0, $this->contar(StatusNota::AguardandoCredito));
        Queue::assertPushed(EmitirNfseJob::class, 2);

        $carteira = Carteira::where('conta_id', $this->conta->id)->first();
        $this->assertSame(0, (int) ($carteira?->disponivel ?? 0));
        $this->assertSame(0, (int) ($carteira?->reservado ?? 0));
        $this->assertSame(0, MovimentoCredito::semIsolamento()->count());
        $this->assertSame(0, (int) NotaFiscal::semIsolamento()->max('ciclo_credito'));
    }

    public function test_em_homologacao_autorizar_ou_recusar_nao_gera_movimento(): void
    {
        $this->emitente(2);
        $this->enviarSemSaldo();
        $creditos = app(CarteiraService::class);

        foreach (NotaFiscal::semIsolamento()->get() as $i => $nota) {
            $i === 0 ? $creditos->consumir($nota) : $creditos->estornar($nota);
        }

        $this->assertSame(0, MovimentoCredito::semIsolamento()->count());
        $this->assertSame(0, (int) (Carteira::where('conta_id', $this->conta->id)->first()?->reservado ?? 0));
    }

    public function test_em_producao_sem_saldo_continua_aguardando_credito(): void
    {
        $this->emitente(1);
        $this->enviarSemSaldo();

        $this->assertSame(2, $this->contar(StatusNota::AguardandoCredito));
        $this->assertSame(0, $this->contar(StatusNota::Pendente));
        Queue::assertNothingPushed();
    }

    public function test_tela_de_conferencia_avisa_que_modo_teste_nao_gasta_creditos(): void
    {
        $this->emitente(2);
        $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id,
            'planilha'    => GeraPlanilha::xlsx($this->emitente->cnpj, [['doc' => '52998224725', 'nome_ref' => 'Cliente', 'codigo' => '010302', 'descricao' => 'Serviço', 'valor' => 100]]),
        ]);
        $imp = Importacao::firstOrFail();

        $this->get(route('importacoes.conferir', $imp))->assertOk()
            ->assertSee('Modo teste: não gasta créditos')->assertDontSee('Saldo insuficiente')->assertDontSee('créditos serão reservados');
    }

    public function test_tela_de_conferencia_em_producao_mostra_o_custo(): void
    {
        $this->emitente(1);
        $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id,
            'planilha'    => GeraPlanilha::xlsx($this->emitente->cnpj, [['doc' => '52998224725', 'nome_ref' => 'Cliente', 'codigo' => '010302', 'descricao' => 'Serviço', 'valor' => 100]]),
        ]);
        $imp = Importacao::firstOrFail();

        $this->get(route('importacoes.conferir', $imp))->assertOk()
            ->assertSee('Custo:')->assertSee('Saldo insuficiente')->assertDontSee('Modo teste: não gasta créditos');
    }
}
