<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Jobs\EmitirNfseJob;
use App\Mail\NfseEmitidaMail;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Services\Nfse\Danfse;
use App\Services\Nfse\NfseXml;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** NFS-e emitida: ler o XML, gerar o DANFSE (PDF), baixar XML/PDF e enviar por e-mail ao cliente. */
class DocumentosNfseTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35503082219534920000118000000000000326113099997840';

    private User $user;
    private Conta $conta;
    private Emitente $emitente;
    private Importacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake([EmitirNfseJob::class]);

        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Empresa Teste', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'BR CONTROLE SERVICOS DE GESTAO DE DADOS LTDA', 'codigo_municipio' => '3550308',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 17.59, 'serie_dps' => '00001', 'ambiente' => 2,
            ]);
        });
    }

    private function xml(): string
    {
        return file_get_contents(base_path('tests/Fixtures/nfse-exemplo.xml'));
    }

    private function notaEmitida(array $extra = []): NotaFiscal
    {
        $this->importacao = $importacao = Importacao::create(['conta_id' => $this->conta->id, 'emitente_id' => $this->emitente->id, 'arquivo_nome' => 'x.csv', 'arquivo_caminho' => 'importacoes/x.txt', 'status' => 'enviada']);

        return NotaFiscal::create($extra + [
            'conta_id' => $this->conta->id, 'emitente_id' => $this->emitente->id, 'importacao_id' => $importacao->id, 'origem' => 'planilha',
            'linha_planilha' => 2, 'status' => StatusNota::Emitida, 'serie_dps' => '00001', 'numero_dps' => 26,
            'tomador_documento' => '19566539000130', 'tomador_nome' => 'Malix Automóveis Ltda – EPP', 'tomador_email' => 'cliente@exemplo.com.br',
            'codigo_servico' => '010302', 'descricao' => 'Manutenção de programas de computação.', 'valor' => 452.50, 'data_competencia' => '2026-11-05',
            'chave_acesso' => self::CHAVE, 'numero_nfse' => '3', 'xml_nfse' => $this->xml(), 'emitida_em' => now(),
        ]);
    }

    // ---------- leitura do XML ----------

    public function test_le_os_campos_da_nfse_autorizada(): void
    {
        $d = NfseXml::ler($this->xml());

        $this->assertSame(self::CHAVE, $d['chave']);
        $this->assertSame('3', $d['numero']);
        $this->assertSame(2, $d['ambiente']);
        $this->assertSame('19534920000118', $d['prestador']['documento']);
        $this->assertSame('BR CONTROLE SERVICOS DE GESTAO DE DADOS LTDA', $d['prestador']['nome']);
        $this->assertSame('02302070', $d['prestador']['cep']);          // zero à esquerda recomposto
        $this->assertSame('19566539000130', $d['tomador']['documento']);
        $this->assertSame('010302', $d['servico']['codigo_nacional']);
        $this->assertSame('452.50', $d['valores']['servico']);
        $this->assertSame('452.50', $d['valores']['liquido']);
        $this->assertSame('26', $d['dps']['numero']);
    }

    public function test_xml_invalido_da_erro_claro(): void
    {
        $this->expectException(\RuntimeException::class);
        NfseXml::ler('isto não é xml');
    }

    // ---------- PDF ----------

    public function test_gera_o_danfse_em_pdf(): void
    {
        $pdf = app(Danfse::class)->pdf($this->notaEmitida());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
    }

    public function test_baixa_o_xml_e_ve_o_pdf(): void
    {
        $nota = $this->notaEmitida();

        $this->actingAs($this->user)->get(route('notas.xml', $nota))->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="NFSe-3-99997840.xml"');

        $resposta = $this->get(route('notas.danfse', $nota))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', $resposta->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $resposta->getContent());

        $this->assertStringContainsString('attachment', $this->get(route('notas.danfse', [$nota, 'baixar' => 1]))->headers->get('Content-Disposition'));
    }

    public function test_nota_que_nao_foi_emitida_nao_tem_documentos(): void
    {
        $nota = $this->notaEmitida(['status' => StatusNota::Rejeitada, 'xml_nfse' => null]);

        $this->actingAs($this->user)->get(route('notas.xml', $nota))->assertNotFound();
        $this->get(route('notas.danfse', $nota))->assertNotFound();
        $this->post(route('notas.email', $nota))->assertNotFound();
    }

    // ---------- e-mail ----------

    public function test_envia_o_email_ao_cliente_com_pdf_e_xml(): void
    {
        Mail::fake();
        $nota = $this->notaEmitida();

        $this->actingAs($this->user)->postJson(route('notas.email', $nota))
            ->assertOk()->assertJson(['ok' => true])->assertJsonPath('mensagem', 'E-mail com o PDF e o XML enviado para cliente@exemplo.com.br.');

        Mail::assertQueued(NfseEmitidaMail::class, fn ($m) => $m->hasTo('cliente@exemplo.com.br') && $m->notaId === $nota->id);
        $this->assertNotNull($nota->fresh()->email_enviado_em);
    }

    public function test_o_email_leva_os_dois_anexos_e_avisa_que_e_teste(): void
    {
        $mail = new NfseEmitidaMail($this->notaEmitida());

        $this->assertStringStartsWith('[TESTE] NFS-e nº 3', $mail->envelope()->subject);
        $this->assertCount(2, $mail->attachments());
        $mail->assertSeeInHtml('sem valor fiscal');
        $mail->assertSeeInHtml(self::CHAVE);
    }

    public function test_varios_emails_na_planilha_vao_todos_e_invalidos_sao_ignorados(): void
    {
        Mail::fake();
        $nota = $this->notaEmitida(['tomador_email' => 'a@exemplo.com , b@exemplo.com;invalido, A@EXEMPLO.com']);

        $this->actingAs($this->user)->postJson(route('notas.email', $nota))->assertOk();

        Mail::assertQueued(NfseEmitidaMail::class, fn ($m) => $m->hasTo('a@exemplo.com') && $m->hasTo('b@exemplo.com') && count($m->to) === 2);
    }

    public function test_sem_email_valido_do_cliente_nao_envia(): void
    {
        Mail::fake();
        $nota = $this->notaEmitida(['tomador_email' => null]);

        $this->actingAs($this->user)->postJson(route('notas.email', $nota))->assertStatus(422);

        Mail::assertNothingQueued();
        $this->assertNull($nota->fresh()->email_enviado_em);
    }

    // ---------- tela ----------

    public function test_linha_da_nota_emitida_mostra_pdf_xml_chave_e_email(): void
    {
        $nota = $this->notaEmitida();

        $this->actingAs($this->user)->get(route('importacoes.show', $this->importacao))->assertOk()
            ->assertSee(route('notas.danfse', $nota), false)->assertSee(route('notas.xml', $nota), false)
            ->assertSee('data-copiar="'.self::CHAVE.'"', false)->assertSee(route('notas.email', $nota), false)
            ->assertSee('Enviar por e-mail…')->assertSee('value="cliente@exemplo.com.br"', false);
    }

    public function test_sem_email_do_cliente_o_envio_pede_para_informar_o_destinatario(): void
    {
        $nota = $this->notaEmitida(['tomador_email' => null]);

        $this->actingAs($this->user)->get(route('importacoes.show', $this->importacao))->assertOk()
            ->assertSee(route('notas.email', $nota), false)->assertSee('Enviar por e-mail (informar)…');
    }

    public function test_email_vai_para_o_cliente_com_copia_para_o_emitente(): void
    {
        Mail::fake();
        $this->emitente->update(['email_copia' => 'nfe@empresa.test']);
        $nota = $this->notaEmitida();

        $this->actingAs($this->user)->post(route('notas.email', $nota))->assertRedirect();

        Mail::assertQueued(\App\Mail\NfseEmitidaMail::class, fn ($m) => $m->hasTo('cliente@exemplo.com.br') && $m->hasCc('nfe@empresa.test'));
    }

    public function test_da_para_trocar_os_emails_so_neste_envio(): void
    {
        Mail::fake();
        $nota = $this->notaEmitida();

        $this->actingAs($this->user)->post(route('notas.email', $nota), ['emails' => 'teste@nossa.test; outro@nossa.test, invalido'])->assertRedirect();

        Mail::assertQueued(\App\Mail\NfseEmitidaMail::class, fn ($m) => $m->hasTo('teste@nossa.test') && $m->hasTo('outro@nossa.test') && ! $m->hasTo('cliente@exemplo.com.br'));
    }

    public function test_notas_ordenam_por_coluna_e_mostram_pendentes(): void
    {
        $this->notaEmitida(['valor' => 900]);
        NotaFiscal::create([
            'conta_id' => $this->conta->id, 'emitente_id' => $this->emitente->id, 'importacao_id' => $this->importacao->id, 'origem' => 'planilha',
            'linha_planilha' => 3, 'status' => StatusNota::Pendente, 'serie_dps' => '00001', 'numero_dps' => 27,
            'tomador_documento' => '19566539000130', 'tomador_nome' => 'Aaa Cliente', 'codigo_servico' => '010302', 'descricao' => 'x', 'valor' => 10, 'data_competencia' => '2026-11-05',
        ]);

        $this->actingAs($this->user)->get(route('notas.index', ['ordem' => 'cliente', 'dir' => 'asc']))->assertOk()
            ->assertSeeInOrder(['Aaa Cliente', 'Malix'])->assertSee('Emitidas')->assertSee('Pendentes')->assertSee('R$ 10,00')->assertSee('setTimeout', false);   // há nota na fila: atualiza sozinho
        $this->actingAs($this->user)->get(route('notas.index', ['ordem' => 'valor; drop table', 'dir' => 'asc']))->assertOk();   // coluna desconhecida é ignorada
    }

    public function test_cancelar_nota_ja_cancelada_volta_para_notas_sem_404(): void
    {
        $nota = $this->notaEmitida(['status' => StatusNota::Cancelada]);

        $this->actingAs($this->user)->get(route('notas.cancelar.form', $nota))->assertRedirect()->assertSessionHas('ok');
        $this->actingAs($this->user)->post(route('notas.cancelar', $nota), [])->assertRedirect()->assertSessionHas('ok');
    }

    public function test_municipio_com_texto_certo_mas_sem_codigo_ibge_e_resolvido_ao_enviar(): void
    {
        \Illuminate\Support\Facades\Http::fake(['servicodados.ibge.gov.br/*' => \Illuminate\Support\Facades\Http::response([['id' => 3547809, 'nome' => 'Santo André']])]);
        // Em produção isto roda numa requisição/job com a conta ativa: aqui simulamos isso
        app(ContaAtual::class)->como($this->conta->id, function () {
            $t = \App\Models\Tomador::create(['conta_id' => $this->conta->id, 'documento' => '19566539000130', 'nome' => 'MBM', 'logradouro' => 'Av. dos Estados', 'numero' => '8000',
                'bairro' => 'Pq. Capuava', 'cep' => '09290030', 'municipio' => 'Santo André', 'uf' => 'SP', 'emails' => ['a@b.co'], 'pendente' => true]);
            $nota = $this->notaEmitida(['status' => StatusNota::Rascunho, 'tomador_id' => $t->id]);

            $this->assertSame([], \App\Services\Nfse\EnvioDeNotas::faltaNoCadastro($nota));
            $this->assertSame('3547809', $t->fresh()->codigo_municipio);
            $this->assertFalse($t->fresh()->pendente);
        });
    }

    public function test_etiqueta_cancelada_tem_fundo_vermelho(): void
    {
        $this->assertStringContainsString('bg-perigo-600', StatusNota::Cancelada->selo());
    }

    public function test_botao_de_cancelar_so_habilita_com_a_confirmacao(): void
    {
        $nota = $this->notaEmitida();

        $this->actingAs($this->user)->get(route('notas.cancelar.form', $nota))->assertOk()
            ->assertSee(':disabled="!confirmo"', false);
    }
}
