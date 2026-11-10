<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Mail\NfseEmitidaMail;
use App\Models\Auditoria;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Services\Nfse\AssinadorXml;
use App\Services\Nfse\CancelamentoNfse;
use App\Services\Nfse\Certificado;
use App\Services\Nfse\FinalizadorNota;
use App\Services\Nfse\RejeicaoSefinException;
use App\Services\Nfse\SefinNacionalClient;
use App\Services\Nfse\ValidadorXsd;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/** Fase 3: cancelamento de NFS-e (evento 101101) e envio automático ao cliente. */
class CancelamentoEmailAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35503082219534920000118000000000000326113099997840';

    private User $dono;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dono = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->dono->id, ['papel' => Papel::Dono->value]);
        $this->dono->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1,
                'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
            ]);
        });
    }

    private function nota(array $extra = []): NotaFiscal
    {
        return NotaFiscal::create($extra + [
            'conta_id' => $this->conta->id, 'emitente_id' => $this->emitente->id, 'origem' => 'planilha', 'status' => StatusNota::Emitida,
            'serie_dps' => '00001', 'ambiente' => 2, 'tomador_documento' => '52998224725', 'tomador_nome' => 'Cliente Alfa', 'tomador_email' => 'cliente@x.com',
            'codigo_servico' => '010302', 'descricao' => 'Suporte', 'valor' => 100, 'data_competencia' => '2026-11-03',
            'numero_nfse' => '7', 'chave_acesso' => self::CHAVE, 'xml_nfse' => '<NFSe/>', 'emitida_em' => now(),
        ]);
    }

    /** Serviço de cancelamento sem certificado real: a assinatura é substituída (o XSD e a regra são os de verdade). */
    private function servico(): CancelamentoNfse
    {
        $assinadorFalso = new class extends AssinadorXml {
            public function assinar(string $xml, Certificado $certificado, string $elementoAssinado = 'infDPS'): string
            {
                return $xml;
            }
        };

        return new CancelamentoNfse(new ValidadorXsd(), $assinadorFalso);
    }

    private function clienteSefin(?array $retorno = ['xml_evento' => '<evento/>'], ?\Throwable $erro = null): SefinNacionalClient
    {
        $c = $this->createMock(SefinNacionalClient::class);
        $erro ? $c->method('cancelar')->willThrowException($erro) : $c->method('cancelar')->willReturn($retorno);

        return $c;
    }

    private function certificado(): Certificado
    {
        return $this->createMock(Certificado::class);
    }

    // ---------- pedido de cancelamento ----------

    public function test_pedido_de_cancelamento_passa_no_xsd_com_o_id_de_59_caracteres(): void
    {
        $nota = $this->nota();
        $nota->setRelation('emitente', $this->emitente);

        $xml = $this->servico()->montarPedido($nota, 1, 'Valor digitado errado na planilha.');

        $this->assertSame([], (new ValidadorXsd)->erros($xml, config('nfse.xsd_evento')));
        $this->assertSame('PRE'.self::CHAVE.'101101', $this->servico()->idPedido(self::CHAVE));
        $this->assertSame(59, strlen($this->servico()->idPedido(self::CHAVE)));
        $this->assertStringContainsString('<CNPJAutor>19534920000118</CNPJAutor>', $xml);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
    }

    public function test_cancela_a_nota_emitida_e_guarda_motivo_e_xml_do_evento(): void
    {
        $nota = $this->nota();

        $this->servico()->cancelar($nota, 2, 'Serviço não foi prestado ao cliente.', $this->clienteSefin(), $this->certificado());

        $nota->refresh();
        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertNotNull($nota->cancelada_em);
        $this->assertSame('2', $nota->cancelamento_codigo);
        $this->assertSame('Serviço não foi prestado ao cliente.', $nota->cancelamento_motivo);
        $this->assertSame('<evento/>', $nota->xml_cancelamento);
    }

    public function test_so_cancela_nota_emitida_com_motivo_valido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->servico()->cancelar($this->nota(['status' => StatusNota::Rejeitada]), 1, 'Motivo com mais de quinze letras.', $this->clienteSefin(), $this->certificado());
    }

    public function test_motivo_curto_ou_codigo_invalido_nao_chega_a_sefin(): void
    {
        $nota = $this->nota();
        $cliente = $this->createMock(SefinNacionalClient::class);
        $cliente->expects($this->never())->method('cancelar');

        foreach ([[1, 'curto'], [3, 'Motivo com mais de quinze letras.']] as [$codigo, $texto]) {
            try {
                $this->servico()->cancelar($nota, $codigo, $texto, $cliente, $this->certificado());
                $this->fail('deveria recusar');
            } catch (InvalidArgumentException) {
                $this->assertSame(StatusNota::Emitida, $nota->fresh()->status);
            }
        }
    }

    public function test_recusa_da_sefin_mantem_a_nota_emitida(): void
    {
        $nota = $this->nota();

        try {
            $this->servico()->cancelar($nota, 1, 'Erro de digitação no valor da nota.', $this->clienteSefin(erro: new RejeicaoSefinException(['E0001 Prazo de cancelamento expirado'])), $this->certificado());
            $this->fail('deveria propagar a recusa');
        } catch (RejeicaoSefinException $e) {
            $this->assertStringContainsString('Prazo', $e->erros[0]);
        }
        $this->assertSame(StatusNota::Emitida, $nota->fresh()->status);
        $this->assertNull($nota->fresh()->cancelada_em);
    }

    public function test_cliente_sefin_envia_o_evento_e_devolve_o_xml(): void
    {
        $xml = '<evento><infEvento/></evento>';
        Http::fake(['*/nfse/'.self::CHAVE.'/eventos' => Http::response(['eventoXmlGZipB64' => base64_encode(gzencode($xml))], 201)]);
        $certificado = $this->certificado();
        $certificado->method('arquivosPem')->willReturn(['cert' => '/dev/null', 'key' => '/dev/null']);

        $r = (new SefinNacionalClient($certificado, 2))->cancelar(self::CHAVE, '<pedRegEvento/>');

        $this->assertSame($xml, $r['xml_evento']);
        Http::assertSent(fn ($req) => $req->method() === 'POST' && isset($req->data()['pedidoRegistroEventoXmlGZipB64']));
    }

    public function test_cliente_sefin_traduz_a_recusa_do_cancelamento(): void
    {
        Http::fake(['*' => Http::response(['erros' => [['codigo' => 'E0100', 'descricao' => 'Evento não permitido', 'complemento' => 'NFS-e já cancelada']]], 400)]);
        $certificado = $this->certificado();
        $certificado->method('arquivosPem')->willReturn(['cert' => '/dev/null', 'key' => '/dev/null']);

        try {
            (new SefinNacionalClient($certificado, 2))->cancelar(self::CHAVE, '<pedRegEvento/>');
            $this->fail('deveria recusar');
        } catch (RejeicaoSefinException $e) {
            $this->assertSame(['E0100 Evento não permitido — NFS-e já cancelada'], $e->erros);
        }
    }

    // ---------- tela e permissão ----------

    private function stubQueCancela(?\Throwable $erro = null): void
    {
        $this->app->instance(CancelamentoNfse::class, new class(new ValidadorXsd(), new AssinadorXml(), $erro) extends CancelamentoNfse {
            public function __construct(ValidadorXsd $x, AssinadorXml $a, private ?\Throwable $erroFalso)
            {
                parent::__construct($x, $a);
            }

            public function cancelar(NotaFiscal $nota, int $codigoMotivo, string $texto, ?SefinNacionalClient $cliente = null, ?Certificado $certificado = null): void
            {
                if ($this->erroFalso) {
                    throw $this->erroFalso;
                }
                $nota->forceFill(['status' => StatusNota::Cancelada, 'cancelada_em' => now(), 'cancelamento_codigo' => (string) $codigoMotivo, 'cancelamento_motivo' => $texto])->save();
            }
        });
    }

    public function test_dono_cancela_pela_tela_e_a_acao_fica_na_auditoria(): void
    {
        $this->stubQueCancela();
        $nota = $this->nota();
        $this->actingAs($this->dono);

        $this->get(route('notas.cancelar.form', $nota))->assertOk()->assertSee('O cancelamento não tem volta')->assertSee('Cancelar NFS-e nº 7');
        $this->post(route('notas.cancelar', $nota), ['motivo_codigo' => 1, 'motivo_texto' => 'Valor digitado errado na planilha.', 'confirmo' => 1])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(StatusNota::Cancelada, $nota->fresh()->status);
        $r = Auditoria::where('acao', 'nota_cancelada')->firstOrFail();
        $this->assertSame('Erro na emissão', $r->detalhes['motivo']);
        $this->assertSame('Valor digitado errado na planilha.', $r->detalhes['justificativa']);
    }

    public function test_sem_confirmacao_ou_motivo_curto_nao_cancela(): void
    {
        $this->stubQueCancela();
        $nota = $this->nota();
        $this->actingAs($this->dono);

        $this->post(route('notas.cancelar', $nota), ['motivo_codigo' => 1, 'motivo_texto' => 'Valor digitado errado na planilha.'])->assertSessionHasErrors('confirmo');
        $this->post(route('notas.cancelar', $nota), ['motivo_codigo' => 1, 'motivo_texto' => 'curto', 'confirmo' => 1])->assertSessionHasErrors('motivo_texto');
        $this->assertSame(StatusNota::Emitida, $nota->fresh()->status);
    }

    public function test_recusa_da_sefin_aparece_na_tela_com_o_motivo_real(): void
    {
        $this->stubQueCancela(new RejeicaoSefinException(['E0100 Prazo de cancelamento expirado']));
        $nota = $this->nota();

        $this->actingAs($this->dono)->from(route('notas.cancelar.form', $nota))->post(route('notas.cancelar', $nota), ['motivo_codigo' => 1, 'motivo_texto' => 'Valor digitado errado na planilha.', 'confirmo' => 1])
            ->assertRedirect(route('notas.cancelar.form', $nota))->assertSessionHasErrors('cancelamento');
        $this->assertStringContainsString('Prazo de cancelamento expirado', session('errors')->first('cancelamento'));
        $this->assertSame(StatusNota::Emitida, $nota->fresh()->status);
    }

    public function test_operador_nao_cancela_nem_ve_o_botao(): void
    {
        $this->stubQueCancela();
        $nota = $this->nota();
        $operador = User::factory()->create();
        $this->conta->usuarios()->attach($operador->id, ['papel' => Papel::Operador->value]);
        $operador->forceFill(['conta_atual_id' => $this->conta->id])->save();

        $this->actingAs($operador)->get(route('notas.cancelar.form', $nota))->assertForbidden();
        $this->post(route('notas.cancelar', $nota), ['motivo_codigo' => 1, 'motivo_texto' => 'Valor digitado errado na planilha.', 'confirmo' => 1])->assertForbidden();
        $this->get(route('notas.index'))->assertOk()->assertDontSee('notas/'.$nota->id.'/cancelar');
        $this->assertSame(StatusNota::Emitida, $nota->fresh()->status);

        $this->actingAs($this->dono)->get(route('notas.index'))->assertOk()->assertSee('notas/'.$nota->id.'/cancelar');
    }

    public function test_nota_cancelada_sai_dos_totais_e_aparece_como_cancelada(): void
    {
        $this->nota(['valor' => 100, 'chave_acesso' => '1'.substr(self::CHAVE, 1)]);
        $this->nota(['valor' => 900, 'status' => StatusNota::Cancelada, 'cancelada_em' => now(), 'cancelamento_motivo' => 'Erro de digitação no valor', 'numero_nfse' => '8']);
        $this->actingAs($this->dono);

        $this->get(route('relatorios.index', ['mes' => '2026-11']))->assertOk()->assertSee('R$ 100,00')->assertDontSee('R$ 1.000,00');
        $this->get(route('notas.index'))->assertOk()->assertSee('Cancelada em');
        $this->get(route('notas.cancelar.form', NotaFiscal::where('status', StatusNota::Cancelada)->firstOrFail()))->assertRedirect(route('notas.index', ['q' => '8']));   // já cancelada: volta para Notas com aviso
    }

    // ---------- e-mail automático ----------

    private function emitida(NotaFiscal $nota): void
    {
        app(FinalizadorNota::class)->emitida($nota, ['chave_acesso' => 'CH'.uniqid(), 'numero_nfse' => '55', 'xml_nfse' => '<NFSe/>']);
    }

    private function empresa(array $dados): void
    {
        Emitente::semIsolamento()->whereKey($this->emitente->id)->update($dados);
    }

    private function novaNota(array $extra = []): NotaFiscal
    {
        return $this->nota($extra + ['status' => StatusNota::Pendente, 'chave_acesso' => null, 'numero_nfse' => null, 'xml_nfse' => null, 'emitida_em' => null]);
    }

    public function test_em_producao_com_a_opcao_ligada_envia_ao_emitir_e_registra(): void
    {
        Mail::fake();
        $this->empresa(['ambiente' => 1, 'enviar_email_automatico' => true]);
        $nota = $this->novaNota();

        $this->emitida($nota);

        Mail::assertQueued(NfseEmitidaMail::class, fn ($m) => $m->hasTo('cliente@x.com'));
        $this->assertNotNull($nota->fresh()->email_enviado_em);
        $this->assertTrue(Auditoria::semIsolamento()->where('acao', 'email_enviado')->firstOrFail()->detalhes['automatico']);
    }

    public function test_nao_envia_em_homologacao_com_opcao_desligada_sem_email_ou_se_ja_enviou(): void
    {
        Mail::fake();

        $this->empresa(['ambiente' => 2, 'enviar_email_automatico' => true]);                  // teste: sempre manual
        $this->emitida($this->novaNota());

        $this->empresa(['ambiente' => 1, 'enviar_email_automatico' => false]);                 // opção desligada
        $this->emitida($this->novaNota());

        $this->empresa(['ambiente' => 1, 'enviar_email_automatico' => true]);
        $this->emitida($this->novaNota(['tomador_email' => null]));                            // cliente sem e-mail
        $jaEnviada = $this->novaNota();
        $jaEnviada->forceFill(['email_enviado_em' => now()])->save();                          // já enviado à mão
        $this->emitida($jaEnviada);

        Mail::assertNothingQueued();
    }

    public function test_falha_no_envio_nao_derruba_a_emissao(): void
    {
        $this->empresa(['ambiente' => 1, 'enviar_email_automatico' => true]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP fora do ar'));
        $nota = $this->novaNota();

        $this->emitida($nota);

        $this->assertSame(StatusNota::Emitida, $nota->fresh()->status);
    }

    public function test_a_opcao_e_salva_no_formulario_da_empresa(): void
    {
        $dados = ['cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1,
            'regime_especial' => 0, 'aliquota_simples' => 6, 'codigo_tributacao_municipal' => '001', 'serie_dps' => '00001', 'ambiente' => 2];

        $this->actingAs($this->dono)->put(route('emitentes.update', $this->emitente), $dados + ['enviar_email_automatico' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->emitente->fresh()->enviar_email_automatico);

        $this->put(route('emitentes.update', $this->emitente), $dados + ['enviar_email_automatico' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($this->emitente->fresh()->enviar_email_automatico);
    }
}
