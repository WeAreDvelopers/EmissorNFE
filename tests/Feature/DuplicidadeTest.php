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
use Tests\Support\GeraPlanilha;
use Tests\TestCase;

/** Controle de duplicidade: arquivo repetido, cobrança parecida com uma nota existente, decisão antes de enviar. */
class DuplicidadeTest extends TestCase
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
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
                'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'Suporte',
            ]);
        });
    }

    private function linha(array $extra = []): array
    {
        return $extra + ['doc' => '52998224725', 'nome_ref' => 'Cliente', 'valor' => 100, 'data' => '2026-11-03'];
    }

    private function importar(array $linhas, array $opcoes = [])
    {
        return $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id, 'planilha' => GeraPlanilha::xlsx($this->emitente->cnpj, $linhas, $opcoes),
        ]);
    }

    private function ultima(): Importacao
    {
        return Importacao::latest('id')->firstOrFail();
    }

    // ---------- arquivo já importado ----------

    public function test_mesmo_arquivo_nao_pode_ser_importado_de_novo_nem_forcando(): void
    {
        $arquivo = GeraPlanilha::xlsx($this->emitente->cnpj, [$this->linha()]);
        $this->actingAs($this->user)->post(route('importacoes.store'), ['emitente_id' => $this->emitente->id, 'planilha' => $arquivo])->assertSessionHasNoErrors();

        $copia = new UploadedFile($arquivo->getRealPath(), 'copia.xlsx', null, null, true);
        $this->post(route('importacoes.store'), ['emitente_id' => $this->emitente->id, 'planilha' => $copia, 'confirmar_repetida' => 1])
            ->assertSessionHasErrors('planilha')->assertSessionHas('importacao_repetida');

        $this->assertSame(1, Importacao::count());
        $this->get(route('importacoes.criar'))->assertOk()->assertSee('Este arquivo já foi importado')->assertDontSee('Enviar mesmo assim');
    }

    public function test_leitura_que_falhou_pode_ser_refeita_com_o_mesmo_arquivo(): void
    {
        $arquivo = GeraPlanilha::xlsx($this->emitente->cnpj, [$this->linha()]);
        $this->actingAs($this->user)->post(route('importacoes.store'), ['emitente_id' => $this->emitente->id, 'planilha' => $arquivo]);
        $this->ultima()->update(['status' => 'falhou']);

        $copia = new UploadedFile($arquivo->getRealPath(), 'copia.xlsx', null, null, true);
        $this->post(route('importacoes.store'), ['emitente_id' => $this->emitente->id, 'planilha' => $copia])->assertSessionHasNoErrors();
        $this->assertSame(2, Importacao::count());
    }

    // ---------- cobrança parecida ----------

    public function test_cobranca_igual_a_uma_nota_ja_emitida_e_sinalizada_e_bloqueia_o_envio(): void
    {
        $this->importar([$this->linha()]);
        $primeira = $this->ultima();
        $this->post(route('importacoes.enviar', $primeira), ['modo' => 'enviar_prontas']);
        NotaFiscal::semIsolamento()->update(['status' => StatusNota::Emitida, 'numero_nfse' => '7']);

        $this->importar([$this->linha(['nome_ref' => 'Outro nome'])]);   // outro arquivo, mesma cobrança aparente
        $segunda = $this->ultima();
        $nota = $segunda->notas()->firstOrFail();
        $this->assertNotNull($nota->duplicidade_de_id);
        $this->assertFalse($nota->duplicidade_aprovada);

        $this->get(route('importacoes.conferir', $segunda))->assertOk()
            ->assertSee('parece repetir uma nota que já existe')->assertSee('NFS-e nº 7')->assertSee('É outra cobrança, manter')
            ->assertSee('Decida as 1 possíveis duplicidades antes de enviar');

        $this->post(route('importacoes.enviar', $segunda), ['modo' => 'enviar_prontas'])->assertSessionHasErrors('duplicidade');
        $this->assertSame('conferir', $segunda->fresh()->status);
        $this->assertSame(StatusNota::Rascunho, $nota->fresh()->status);
    }

    public function test_aprovar_libera_o_envio(): void
    {
        $this->importar([$this->linha()]);
        $this->post(route('importacoes.enviar', $this->ultima()), ['modo' => 'enviar_prontas']);
        $this->importar([$this->linha(['nome_ref' => 'X'])]);
        $segunda = $this->ultima();
        $nota = $segunda->notas()->firstOrFail();

        $this->post(route('notas.duplicidade.aprovar', $nota))->assertRedirect();
        $this->assertTrue($nota->fresh()->duplicidade_aprovada);

        $this->post(route('importacoes.enviar', $segunda), ['modo' => 'enviar_prontas'])->assertRedirect(route('importacoes.show', $segunda));
        $this->assertSame(StatusNota::Pendente, $nota->fresh()->status);
    }

    public function test_excluir_a_linha_repetida_tambem_libera(): void
    {
        $this->importar([$this->linha()]);
        $this->post(route('importacoes.enviar', $this->ultima()), ['modo' => 'enviar_prontas']);
        $this->importar([$this->linha(['nome_ref' => 'X']), $this->linha(['doc' => '11444777000161', 'nome_ref' => 'Outro'])]);
        $segunda = $this->ultima();
        $repetida = $segunda->notas()->whereNotNull('duplicidade_de_id')->firstOrFail();

        $this->delete(route('notas.excluir', $repetida))->assertRedirect();
        $this->post(route('importacoes.enviar', $segunda), ['modo' => 'enviar_prontas'])->assertRedirect(route('importacoes.show', $segunda));
        $this->assertSame(1, $segunda->notas()->count());
    }

    public function test_linha_repetida_dentro_do_mesmo_arquivo_e_sinalizada_so_a_segunda(): void
    {
        $this->importar([$this->linha(), $this->linha()]);
        $notas = $this->ultima()->notas()->orderBy('linha_planilha')->get();

        $this->assertNull($notas[0]->duplicidade_de_id);
        $this->assertSame($notas[0]->id, $notas[1]->duplicidade_de_id);
    }

    public function test_valor_ou_data_ou_cliente_diferentes_nao_sao_sinalizados(): void
    {
        $this->importar([$this->linha(), $this->linha(['valor' => 101]), $this->linha(['data' => '2026-11-04']), $this->linha(['doc' => '11444777000161'])]);

        $this->assertSame(0, NotaFiscal::semIsolamento()->whereNotNull('duplicidade_de_id')->count());
    }

    public function test_sem_destinatario_nao_entra_na_deteccao(): void
    {
        $this->importar([$this->linha(['doc' => 'sem destinatário informado']), $this->linha(['doc' => 'sem destinatário informado'])]);

        $this->assertSame(0, NotaFiscal::semIsolamento()->whereNotNull('duplicidade_de_id')->count());
    }

    public function test_outra_empresa_com_a_mesma_cobranca_nao_conta(): void
    {
        $this->importar([$this->linha()]);
        $this->post(route('importacoes.enviar', $this->ultima()), ['modo' => 'enviar_prontas']);

        $outra = app(ContaAtual::class)->como($this->conta->id, fn () => Emitente::create([
            'cnpj' => '11444777000161', 'razao_social' => 'Outra', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1,
            'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2, 'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'x',
        ]));
        $this->post(route('emitentes.administrar', $outra));   // escolhe a outra empresa no menu lateral
        $this->post(route('importacoes.store'), ['emitente_id' => $outra->id, 'planilha' => GeraPlanilha::xlsx($outra->cnpj, [$this->linha()])])->assertSessionHasNoErrors();

        $this->assertSame(0, NotaFiscal::semIsolamento()->where('emitente_id', $outra->id)->whereNotNull('duplicidade_de_id')->count());
    }
}
