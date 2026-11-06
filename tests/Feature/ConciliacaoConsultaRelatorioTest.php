<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Services\Nfse\ConciliacaoNfse;
use App\Services\Nfse\FinalizadorNota;
use App\Services\Nfse\SefinNacionalClient;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Conciliação com a SEFIN, consulta de notas e relatório mensal. */
class ConciliacaoConsultaRelatorioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3,
                'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
            ]);
        });
    }

    private function nota(array $extra = []): NotaFiscal
    {
        return NotaFiscal::create($extra + [
            'conta_id' => $this->conta->id, 'emitente_id' => $this->emitente->id, 'origem' => 'planilha', 'status' => StatusNota::Emitida,
            'serie_dps' => '00001', 'ambiente' => 2, 'tomador_documento' => '52998224725', 'tomador_nome' => 'Cliente Alfa', 'codigo_servico' => '010302',
            'descricao' => 'Suporte', 'valor' => 100, 'data_competencia' => now()->startOfMonth()->addDays(2)->toDateString(),
            'numero_nfse' => '7', 'chave_acesso' => 'CHAVE'.uniqid(), 'xml_nfse' => '<NFSe/>', 'emitida_em' => now(),
        ]);
    }

    /** Conciliação com um cliente SEFIN de mentira (não existe certificado nos testes). */
    private function conciliacaoCom(?array $retorno): ConciliacaoNfse
    {
        $cliente = $this->createMock(SefinNacionalClient::class);
        $cliente->method('consultarPorDps')->willReturn($retorno);

        return new class(app(FinalizadorNota::class), $cliente) extends ConciliacaoNfse {
            public function __construct(FinalizadorNota $f, private SefinNacionalClient $falso)
            {
                parent::__construct($f);
            }

            protected function cliente(Emitente $emitente): SefinNacionalClient
            {
                return $this->falso;
            }
        };
    }

    // ---------- conciliação ----------

    public function test_nota_que_falhou_mas_a_sefin_autorizou_vira_emitida_com_numero_e_xml(): void
    {
        $nota = $this->nota(['status' => StatusNota::Falhou, 'numero_dps' => 5, 'numero_nfse' => null, 'chave_acesso' => null, 'xml_nfse' => null, 'emitida_em' => null, 'erros' => ['timeout']]);

        $r = $this->conciliacaoCom(['chave_acesso' => 'CHAVE-OK', 'numero_nfse' => '321', 'xml_nfse' => '<NFSe/>'])->conciliar($nota);

        $this->assertSame(ConciliacaoNfse::AUTORIZADA, $r);
        $nota->refresh();
        $this->assertSame(StatusNota::Emitida, $nota->status);
        $this->assertSame('321', $nota->numero_nfse);
        $this->assertSame('CHAVE-OK', $nota->chave_acesso);
        $this->assertNull($nota->erros);
        $this->assertNotNull($nota->emitida_em);
    }

    public function test_sefin_sem_a_nota_nao_muda_nada_e_registra_a_consulta(): void
    {
        $nota = $this->nota(['status' => StatusNota::Falhou, 'numero_dps' => 5, 'numero_nfse' => null, 'chave_acesso' => null]);

        $this->assertSame(ConciliacaoNfse::NAO_ENCONTRADA, $this->conciliacaoCom(null)->conciliar($nota));
        $this->assertSame(StatusNota::Falhou, $nota->fresh()->status);
        $this->assertNotNull($nota->fresh()->conciliada_em);
    }

    public function test_nota_que_nunca_foi_enviada_nao_e_consultada(): void
    {
        $nota = $this->nota(['status' => StatusNota::Pendente, 'numero_dps' => null]);

        $this->assertSame(ConciliacaoNfse::SEM_ENVIO, $this->conciliacaoCom(['chave_acesso' => 'X'])->conciliar($nota));
        $this->assertSame(StatusNota::Pendente, $nota->fresh()->status);
    }

    public function test_so_notas_incertas_e_ainda_nao_conferidas_recentemente_sao_candidatas(): void
    {
        $travada = $this->nota(['status' => StatusNota::Enviando, 'numero_dps' => 1]);
        $falhou = $this->nota(['status' => StatusNota::Falhou, 'numero_dps' => 2]);
        $recente = $this->nota(['status' => StatusNota::Enviando, 'numero_dps' => 3]);
        $jaConferida = $this->nota(['status' => StatusNota::Falhou, 'numero_dps' => 4, 'conciliada_em' => now()->subMinutes(20)]);
        $this->nota(['status' => StatusNota::Emitida, 'numero_dps' => 6]);
        $this->nota(['status' => StatusNota::Rejeitada, 'numero_dps' => 7]);

        NotaFiscal::semIsolamento()->whereKey($travada->id)->update(['updated_at' => now()->subHour()]);
        NotaFiscal::semIsolamento()->whereKey($recente->id)->update(['updated_at' => now()->subMinutes(5)]);

        $ids = app(ConciliacaoNfse::class)->candidatas()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$travada->id, $falhou->id], $ids);
        $this->assertNotContains($jaConferida->id, $ids);
    }

    public function test_comando_recupera_as_notas_autorizadas(): void
    {
        $nota = $this->nota(['status' => StatusNota::Falhou, 'numero_dps' => 5, 'numero_nfse' => null, 'chave_acesso' => null]);
        $this->app->instance(ConciliacaoNfse::class, $this->conciliacaoCom(['chave_acesso' => 'C1', 'numero_nfse' => '9']));

        $this->artisan('nfse:conciliar')->expectsOutput('1 nota(s) recuperada(s).')->assertSuccessful();
        $this->assertSame(StatusNota::Emitida, $nota->fresh()->status);
    }

    public function test_botao_conferir_na_sefin_responde_com_mensagem_clara(): void
    {
        $nota = $this->nota(['status' => StatusNota::Falhou, 'numero_dps' => 5, 'numero_nfse' => null, 'chave_acesso' => null]);
        $this->app->instance(ConciliacaoNfse::class, $this->conciliacaoCom(null));

        $this->actingAs($this->user)->post(route('notas.conciliar', $nota))->assertRedirect()->assertSessionHas('ok');
        $this->assertStringContainsString('pode ser reenviada com segurança', session('ok'));
    }

    public function test_nao_confere_nota_que_ja_esta_emitida(): void
    {
        $this->actingAs($this->user)->post(route('notas.conciliar', $this->nota()))->assertStatus(422);
    }

    // ---------- conferência com a planilha ----------

    public function test_tela_do_lote_fecha_o_total_da_planilha_com_o_emitido(): void
    {
        $imp = Importacao::create(['conta_id' => $this->conta->id, 'emitente_id' => $this->emitente->id, 'arquivo_nome' => 'novembro.xlsx', 'arquivo_caminho' => 'x', 'status' => 'enviada', 'total_planilha' => 300]);
        $this->nota(['importacao_id' => $imp->id, 'valor' => 100]);
        $this->nota(['importacao_id' => $imp->id, 'valor' => 100, 'numero_nfse' => '8', 'chave_acesso' => 'C8']);
        $this->nota(['importacao_id' => $imp->id, 'valor' => 100, 'status' => StatusNota::Rejeitada, 'numero_nfse' => null, 'chave_acesso' => null]);

        $this->actingAs($this->user)->get(route('importacoes.show', $imp))->assertOk()
            ->assertSee('Conferência com a planilha')->assertSee('R$ 300,00')->assertSee('R$ 200,00')->assertSee('Fechou');

        $imp->update(['total_planilha' => 450]);
        $this->get(route('importacoes.show', $imp))->assertOk()->assertSee('R$ 150,00')->assertSee('célula E1');
    }

    // ---------- consulta de notas ----------

    public function test_consulta_busca_por_cliente_numero_chave_e_situacao(): void
    {
        $this->nota(['tomador_nome' => 'Padaria Bom Grão', 'numero_nfse' => '11', 'chave_acesso' => 'CHAVEPADARIA']);
        $this->nota(['tomador_nome' => 'Oficina do Zé', 'tomador_documento' => '11444777000161', 'numero_nfse' => '12', 'chave_acesso' => 'CHAVEOFICINA']);
        $this->nota(['tomador_nome' => 'Mercado Recusado', 'status' => StatusNota::Rejeitada, 'numero_nfse' => null, 'chave_acesso' => null]);
        $this->nota(['tomador_nome' => 'Lote Nao Enviado', 'status' => StatusNota::Rascunho, 'numero_nfse' => null, 'chave_acesso' => null]);
        $this->actingAs($this->user);

        $this->get(route('notas.index'))->assertOk()->assertSee('Padaria Bom Grão')->assertSee('Oficina do Zé')->assertSee('Mercado Recusado')->assertDontSee('Lote Nao Enviado');
        $this->get(route('notas.index', ['q' => 'padaria']))->assertOk()->assertSee('Padaria Bom Grão')->assertDontSee('Oficina do Zé');
        $this->get(route('notas.index', ['q' => '12']))->assertOk()->assertSee('Oficina do Zé')->assertDontSee('Padaria Bom Grão');
        $this->get(route('notas.index', ['q' => 'CHAVEPADARIA']))->assertOk()->assertSee('Padaria Bom Grão')->assertDontSee('Oficina do Zé');
        $this->get(route('notas.index', ['q' => '11.444.777']))->assertOk()->assertSee('Oficina do Zé')->assertDontSee('Padaria Bom Grão');
        $this->get(route('notas.index', ['grupo' => 'atencao']))->assertOk()->assertSee('Mercado Recusado')->assertDontSee('Padaria Bom Grão');
        $this->get(route('notas.index', ['grupo' => 'emitida']))->assertOk()->assertSee('Padaria Bom Grão')->assertDontSee('Mercado Recusado');
    }

    public function test_consulta_filtra_por_competencia_e_so_mostra_notas_da_propria_conta(): void
    {
        $this->nota(['tomador_nome' => 'Cliente de Novembro', 'data_competencia' => '2026-11-10']);
        $this->nota(['tomador_nome' => 'Cliente de Outubro', 'data_competencia' => '2026-10-10']);

        $outra = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Profitcar', 'documento' => '11444777000161', 'email_financeiro' => 'p@x.com']);
        $emitenteOutra = app(ContaAtual::class)->como($outra->id, fn () => Emitente::create([
            'cnpj' => '11444777000161', 'razao_social' => 'Outra', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
        ]));
        $this->nota(['conta_id' => $outra->id, 'emitente_id' => $emitenteOutra->id, 'tomador_nome' => 'Cliente Alheio']);

        $this->actingAs($this->user)->get(route('notas.index', ['de' => '2026-11-01', 'ate' => '2026-11-30']))->assertOk()
            ->assertSee('Cliente de Novembro')->assertDontSee('Cliente de Outubro')->assertDontSee('Cliente Alheio');
    }

    // ---------- relatório ----------

    public function test_relatorio_do_mes_soma_so_o_que_foi_emitido_na_competencia(): void
    {
        $this->nota(['valor' => 100, 'data_competencia' => '2026-11-03', 'tomador_nome' => 'Cliente A']);
        $this->nota(['valor' => 250.5, 'data_competencia' => '2026-11-04', 'tomador_nome' => 'Cliente B', 'tomador_documento' => '11444777000161', 'numero_nfse' => '8', 'chave_acesso' => 'C8']);
        $this->nota(['valor' => 300, 'data_competencia' => '2026-11-05', 'sem_tomador' => true, 'tomador_documento' => '', 'tomador_nome' => '', 'numero_nfse' => '9', 'chave_acesso' => 'C9']);
        $this->nota(['valor' => 999, 'data_competencia' => '2026-10-03', 'tomador_nome' => 'Cliente Outubro', 'numero_nfse' => '10', 'chave_acesso' => 'C10']);
        $this->nota(['valor' => 50, 'data_competencia' => '2026-11-06', 'status' => StatusNota::Rejeitada, 'numero_nfse' => null, 'chave_acesso' => null]);

        $this->actingAs($this->user)->get(route('relatorios.index', ['mes' => '2026-11']))->assertOk()
            ->assertSee('R$ 650,50')->assertSee('R$ 300,00 sem destinatário')->assertSee('Cliente A')->assertSee('Sem destinatário informado')
            ->assertDontSee('Cliente Outubro')->assertSee('1 recusadas');
    }

    public function test_csv_do_relatorio_traz_as_emitidas_do_mes_com_bom_e_ponto_e_virgula(): void
    {
        $this->nota(['valor' => 1234.5, 'data_competencia' => '2026-11-03', 'tomador_nome' => 'Cliente A', 'numero_nfse' => '7']);
        $this->nota(['valor' => 10, 'data_competencia' => '2026-10-03', 'tomador_nome' => 'Cliente Outubro', 'numero_nfse' => '8', 'chave_acesso' => 'C8']);

        $r = $this->actingAs($this->user)->get(route('relatorios.csv', ['mes' => '2026-11']));
        $r->assertOk()->assertDownload('notas-emitidas-2026-11.csv');
        $csv = $r->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Competência;"NFS-e nº";', $csv);
        $this->assertStringContainsString('03/11/2026;7;', $csv);
        $this->assertStringContainsString('1234,50', $csv);
        $this->assertStringNotContainsString('Cliente Outubro', $csv);
    }

    public function test_mes_invalido_cai_no_mes_atual(): void
    {
        $this->actingAs($this->user)->get(route('relatorios.index', ['mes' => 'lixo']))->assertOk()->assertSee(now()->format('Y-m'), false);
    }
}
