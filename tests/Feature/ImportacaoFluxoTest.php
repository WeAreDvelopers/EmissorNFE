<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Enums\TipoMovimento;
use App\Jobs\EmitirNfseJob;
use App\Models\Carteira;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Services\Creditos\CarteiraService;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportacaoFluxoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake([EmitirNfseJob::class]); // a emissão real (certificado + SEFIN) não roda nos testes

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

    private function creditos(int $qtd): void
    {
        app(CarteiraService::class)->creditar($this->conta->id, $qtd, TipoMovimento::Bonus, validadeDias: 30);
    }

    private function csv(int $validas, bool $comInvalida = true): UploadedFile
    {
        $docs = ['52998224725', '11444777000161', '11222333000181', '39053344705', '11222333000181'];
        $linhas = ['tomador_documento;tomador_nome;tomador_email;codigo_servico;codigo_nbs;descricao;valor;data_competencia'];
        for ($i = 0; $i < $validas; $i++) {
            $linhas[] = $docs[$i % count($docs)].";Cliente $i;;010701;;Serviço $i;1.250,00;".now()->subDay()->format('d/m/Y');
        }
        if ($comInvalida) {
            $linhas[] = '12345;Studio Lume;;010701;;Serviço X;700,00;'.now()->subDay()->format('d/m/Y');
        }

        return UploadedFile::fake()->createWithContent('lote.csv', implode("\n", $linhas)."\n");
    }

    private function importar(int $validas, bool $comInvalida = true): Importacao
    {
        $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id, 'planilha' => $this->csv($validas, $comInvalida),
        ])->assertRedirect();

        return Importacao::firstOrFail();
    }

    private function contar(StatusNota $s): int
    {
        return NotaFiscal::semIsolamento()->where('status', $s)->count();
    }

    public function test_ler_e_conferir_nao_gasta_credito_nem_emite(): void
    {
        $this->creditos(10);
        $imp = $this->importar(3);

        $this->assertSame('conferir', $imp->fresh()->status);
        $this->assertSame(3, $this->contar(StatusNota::Rascunho));
        $this->assertSame(1, $this->contar(StatusNota::Invalida));
        $this->assertSame(0, (int) Carteira::where('conta_id', $this->conta->id)->value('reservado'));
        Queue::assertNothingPushed();

        // Listagem completa: todas as linhas e colunas da planilha, com filtro
        $this->get(route('importacoes.conferir', $imp))->assertOk()
            ->assertSee('Todas as linhas da planilha')->assertSee('Cliente 0')->assertSee('Cliente 2')->assertSee('Studio Lume')
            ->assertSee('Serviço 1')->assertSee('1.250,00');
        $this->get(route('importacoes.conferir', [$imp, 'ver' => 'problemas']))->assertOk()
            ->assertSee('Studio Lume')->assertDontSee('Cliente 0');
        $this->get(route('importacoes.conferir', [$imp, 'ver' => 'prontas']))->assertOk()
            ->assertSee('Cliente 0')->assertDontSee('Studio Lume</td>', false);

        $this->get(route('importacoes.conferir', $imp))->assertOk()
            ->assertSee('Encontramos 3 notas prontas e 1 com problema')
            ->assertSee('tamanho errado') // mensagem em português
            ->assertDontSee('dígito verificador'); // e só uma mensagem por campo
    }

    public function test_corrigir_na_tela_e_enviar_reserva_credito_e_enfileira(): void
    {
        $this->creditos(10);
        $imp = $this->importar(3);
        $invalida = NotaFiscal::semIsolamento()->where('status', StatusNota::Invalida)->firstOrFail();

        $this->actingAs($this->user)->patch(route('notas.corrigir', $invalida), ['tomador_documento' => '529.982.247-25'])
            ->assertRedirectContains(route('importacoes.conferir', $imp));
        $this->assertSame(4, $this->contar(StatusNota::Rascunho));
        $this->assertSame(0, $imp->fresh()->linhas_invalidas);

        $this->post(route('importacoes.enviar', $imp), ['modo' => 'enviar_prontas'])->assertRedirect(route('importacoes.show', $imp));

        Queue::assertPushed(EmitirNfseJob::class, 4);
        $this->assertSame(4, $this->contar(StatusNota::Pendente));
        $this->assertSame(4, (int) Carteira::where('conta_id', $this->conta->id)->value('reservado'));
        $this->assertSame(6, (int) Carteira::where('conta_id', $this->conta->id)->value('disponivel'));
    }

    public function test_correcao_invalida_e_recusada_com_mensagem_clara(): void
    {
        $this->creditos(10);
        $this->importar(1);
        $invalida = NotaFiscal::semIsolamento()->where('status', StatusNota::Invalida)->firstOrFail();

        $this->actingAs($this->user)->from('/x')->patch(route('notas.corrigir', $invalida), ['tomador_documento' => '123'])
            ->assertSessionHasErrors('tomador_documento');
        $this->assertSame(StatusNota::Invalida, $invalida->fresh()->status);
    }

    public function test_editar_qualquer_linha_na_conferencia_inclusive_as_prontas(): void
    {
        $this->creditos(10);
        $imp = $this->importar(2);
        $pronta = NotaFiscal::semIsolamento()->where('status', StatusNota::Rascunho)->orderBy('linha_planilha')->firstOrFail();

        // A listagem oferece o botão e o formulário de edição com todos os campos
        $this->get(route('importacoes.conferir', $imp))->assertOk()
            ->assertSee('Editar')->assertSee('name="descricao"', false)->assertSee('Salvar linha');

        $this->from(route('importacoes.conferir', [$imp, 'ver' => 'prontas']))
            ->patch(route('notas.corrigir', $pronta), [
                'tomador_nome' => 'Cliente Editado', 'descricao' => 'Descrição nova', 'valor' => '2.000,50', 'data_competencia' => now()->subDays(2)->format('d/m/Y'),
            ])->assertRedirectContains('ver=prontas')->assertSessionHas('ok');

        $pronta->refresh();
        $this->assertSame('Cliente Editado', $pronta->tomador_nome);
        $this->assertSame('Descrição nova', $pronta->descricao);
        $this->assertEquals('2000.50', $pronta->valor);
        $this->assertSame(StatusNota::Rascunho, $pronta->status);   // continua pronta, sem custo
        Queue::assertNothingPushed();

        $this->get(route('importacoes.conferir', $imp))->assertSee('Cliente Editado')->assertSee('2.000,50');
    }

    public function test_edicao_invalida_nao_grava_e_reabre_o_formulario(): void
    {
        $this->creditos(10);
        $imp = $this->importar(1, false);
        $pronta = NotaFiscal::semIsolamento()->where('status', StatusNota::Rascunho)->firstOrFail();

        $this->from(route('importacoes.conferir', $imp))
            ->patch(route('notas.corrigir', $pronta), ['_nota' => $pronta->id, 'valor' => '0', 'tomador_nome' => 'Digitado'])
            ->assertSessionHasErrors('valor');

        $this->assertSame('Cliente 0', $pronta->fresh()->tomador_nome); // nada foi salvo
        $this->followingRedirects()->from(route('importacoes.conferir', $imp))
            ->patch(route('notas.corrigir', $pronta), ['_nota' => $pronta->id, 'valor' => '0', 'tomador_nome' => 'Digitado'])
            ->assertSee('maior que zero')->assertSee('value="Digitado"', false); // reabre com o que a pessoa digitou
    }

    public function test_enviar_sem_saldo_suficiente_deixa_o_resto_aguardando_credito(): void
    {
        $this->creditos(2);
        $imp = $this->importar(4, false);

        $this->actingAs($this->user)->post(route('importacoes.enviar', $imp), ['modo' => 'enviar_prontas']);

        Queue::assertPushed(EmitirNfseJob::class, 2);
        $this->assertSame(2, $this->contar(StatusNota::Pendente));
        $this->assertSame(2, $this->contar(StatusNota::AguardandoCredito));
    }

    public function test_corrigir_antes_nao_envia_nada(): void
    {
        $this->creditos(10);
        $imp = $this->importar(2);

        $this->actingAs($this->user)->post(route('importacoes.enviar', $imp), ['modo' => 'corrigir_antes'])->assertRedirect();

        $this->assertSame('conferir', $imp->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_recusada_e_corrigida_com_codigo_novo_e_aplicada_nas_outras(): void
    {
        $this->creditos(10);
        $imp = $this->importar(3, false);
        $this->actingAs($this->user)->post(route('importacoes.enviar', $imp), ['modo' => 'enviar_prontas']);

        // Simula a prefeitura recusando 3 notas pelo mesmo código (o job real devolveria o crédito)
        $notas = NotaFiscal::semIsolamento()->get();
        foreach ($notas as $n) {
            $n->update(['status' => StatusNota::Rejeitada, 'erros' => ['E0310 - Código de tributação nacional inexistente']]);
            app(CarteiraService::class)->estornar($n);
        }
        $imp->update(['status' => 'enviada']);
        Queue::fake([EmitirNfseJob::class]); // zera o registro para contar só o reenvio

        $primeira = $notas->first();
        $this->get(route('importacoes.show', [$imp, 'filtro' => 'atencao', 'nota' => $primeira->id]))->assertOk()
            ->assertSee('Recusada pela prefeitura')->assertSee('não é aceito pela prefeitura')->assertSee('Aplicar também nas outras 2');

        $this->patch(route('notas.corrigir', $primeira), ['codigo_servico' => '171101', 'aplicar_em_todas' => 1])->assertRedirect();

        Queue::assertPushed(EmitirNfseJob::class, 3);
        $this->assertSame(3, NotaFiscal::semIsolamento()->where('codigo_servico', '171101')->count());
        $this->assertSame(3, $this->contar(StatusNota::Pendente));
    }

    public function test_falhas_reenviadas_em_lote(): void
    {
        $this->creditos(10);
        $imp = $this->importar(2, false);
        $this->actingAs($this->user)->post(route('importacoes.enviar', $imp), ['modo' => 'enviar_prontas']);
        foreach (NotaFiscal::semIsolamento()->get() as $n) {
            $n->update(['status' => StatusNota::Falhou, 'erros' => ['timeout']]);
            app(CarteiraService::class)->estornar($n);
        }
        $imp->update(['status' => 'enviada']);
        Queue::fake([EmitirNfseJob::class]);

        $this->post(route('importacoes.reenviar-falhas', $imp))->assertSessionHas('ok', '2 notas reenviadas.');
        Queue::assertPushed(EmitirNfseJob::class, 2);
    }

    public function test_telas_do_dia_a_dia_respondem(): void
    {
        $this->creditos(10);
        $imp = $this->importar(2);

        $this->actingAs($this->user);
        $this->get(route('inicio'))->assertOk()->assertSee('1 nota precisa de você')->assertSee('Importar planilha');
        $this->get(route('importacoes.index'))->assertOk()->assertSee('lote.csv')->assertSee('Aguardando sua conferência');
        $this->get(route('importacoes.criar'))->assertOk()->assertSee('Modo teste');
        $this->get(route('pendencias'))->assertOk()->assertSee('Dado inválido');
        $nota = NotaFiscal::semIsolamento()->where('status', StatusNota::Invalida)->firstOrFail();
        $this->get(route('pendencias', ['nota' => $nota->id]))->assertOk()->assertSee('tamanho errado')->assertSee('Confirmar correção');
        $this->get(route('importacoes.modelo'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    /** Importa, confere e envia um lote; devolve o lote original já "enviada". */
    private function loteEnviado(int $validas = 3): Importacao
    {
        $imp = $this->importar($validas, false);
        $this->actingAs($this->user)->post(route('importacoes.enviar', $imp), ['modo' => 'enviar_prontas']);

        return $imp->fresh();
    }

    public function test_reprocessar_copia_o_lote_enviado_para_um_lote_novo_editavel(): void
    {
        $this->creditos(20);
        $original = $this->loteEnviado(3);
        $this->assertTrue($original->foiEnviada());

        $this->actingAs($this->user)->post(route('importacoes.reprocessar', $original))->assertRedirect();

        $novo = Importacao::semIsolamento()->where('reprocessada_de_id', $original->id)->firstOrFail();
        $this->assertSame('conferir', $novo->status);
        $this->assertSame(3, NotaFiscal::semIsolamento()->where('importacao_id', $novo->id)->where('status', StatusNota::Rascunho)->count());
        // o lote original e as notas dele não são tocados
        $this->assertSame(3, NotaFiscal::semIsolamento()->where('importacao_id', $original->id)->where('status', StatusNota::Pendente)->count());

        $this->get(route('importacoes.editar', $novo))->assertOk()
            ->assertSee('Reprocessar lote')->assertSee('Isto cria notas novas')->assertSee('Cliente 0')->assertSee('Adicionar linha');
        $this->get(route('importacoes.show', $original))->assertSee('Reprocessar');
    }

    public function test_so_reprocessa_lote_que_ja_foi_enviado(): void
    {
        $this->creditos(5);
        $naoEnviado = $this->importar(2, false);

        $this->actingAs($this->user)->post(route('importacoes.reprocessar', $naoEnviado))->assertStatus(422);

        // depois de enviado, o lote deixa de ser editável (para mudar, reprocessa)
        $this->post(route('importacoes.enviar', $naoEnviado), ['modo' => 'enviar_prontas']);
        $this->get(route('importacoes.editar', $naoEnviado))->assertStatus(422);
    }

    public function test_editor_edita_adiciona_e_remove_linhas_sem_o_excel(): void
    {
        $this->creditos(30);
        $original = $this->loteEnviado(3);
        $this->actingAs($this->user)->post(route('importacoes.reprocessar', $original));
        $novo = Importacao::semIsolamento()->where('reprocessada_de_id', $original->id)->firstOrFail();
        $notas = NotaFiscal::semIsolamento()->where('importacao_id', $novo->id)->orderBy('linha_planilha')->get();
        $data = now()->subDay()->format('d/m/Y');

        $resposta = $this->putJson(route('importacoes.linhas.salvar', $novo), ['linhas' => [
            // 1ª: editada (nome, valor, competência)
            ['id' => $notas[0]->id, 'tomador_documento' => '11.444.777/0001-61', 'tomador_nome' => 'Cliente Alterado', 'tomador_email' => '', 'codigo_servico' => '010701', 'codigo_nbs' => '', 'descricao' => 'Novo serviço', 'valor' => '3.000,00', 'data_competencia' => $data],
            // 2ª: some da lista (removida) — 3ª: mantida como está
            ['id' => $notas[2]->id, 'tomador_documento' => $notas[2]->tomador_documento, 'tomador_nome' => $notas[2]->tomador_nome, 'tomador_email' => '', 'codigo_servico' => '010701', 'codigo_nbs' => '', 'descricao' => $notas[2]->descricao, 'valor' => '1250,00', 'data_competencia' => $data],
            // nova e válida
            ['tomador_documento' => '529.982.247-25', 'tomador_nome' => 'Cliente Novo', 'tomador_email' => '', 'codigo_servico' => '170101', 'codigo_nbs' => '', 'descricao' => 'Linha adicionada na tela', 'valor' => '500,00', 'data_competencia' => $data],
            // nova e inválida (CPF curto): é gravada, marcada com o motivo
            ['tomador_documento' => '123', 'tomador_nome' => 'Com Erro', 'tomador_email' => '', 'codigo_servico' => '010701', 'codigo_nbs' => '', 'descricao' => 'x', 'valor' => '10,00', 'data_competencia' => $data],
            // em branco: ignorada
            ['tomador_documento' => '', 'tomador_nome' => '', 'tomador_email' => '', 'codigo_servico' => '', 'codigo_nbs' => '', 'descricao' => '', 'valor' => '', 'data_competencia' => ''],
        ]])->assertOk()->assertJson(['ok' => true, 'total' => 4, 'invalidas' => 1, 'prontas' => 3]);

        $this->assertCount(4, $resposta->json('linhas'));
        $this->assertNotNull($resposta->json('linhas.3.id'));                         // linhas novas voltam com id
        $this->assertStringContainsString('tamanho errado', implode(' ', $resposta->json('linhas.3.erros')));

        $this->assertNull(NotaFiscal::semIsolamento()->find($notas[1]->id));          // removida
        $this->assertSame('Cliente Alterado', $notas[0]->fresh()->tomador_nome);
        $this->assertEquals('3000.00', $notas[0]->fresh()->valor);
        $this->assertSame(3, NotaFiscal::semIsolamento()->where('importacao_id', $original->id)->count()); // original intacto

        // segue para a conferência de sempre e só as prontas são enviadas
        $this->get(route('importacoes.conferir', $novo))->assertOk()
            ->assertSee('Encontramos 3 notas prontas e 1 com problema')->assertSee('Cliente Novo');
        Queue::fake([EmitirNfseJob::class]);
        $this->post(route('importacoes.enviar', $novo), ['modo' => 'enviar_prontas']);
        Queue::assertPushed(EmitirNfseJob::class, 3);
    }

    public function test_apagar_todas_as_linhas_do_editor_deixa_o_lote_vazio(): void
    {
        $this->creditos(10);
        $original = $this->loteEnviado(2);
        $this->actingAs($this->user)->post(route('importacoes.reprocessar', $original));
        $novo = Importacao::semIsolamento()->where('reprocessada_de_id', $original->id)->firstOrFail();

        $this->putJson(route('importacoes.linhas.salvar', $novo), ['linhas' => []])->assertOk()->assertJson(['total' => 0, 'invalidas' => 0]);
        $this->assertSame(0, NotaFiscal::semIsolamento()->where('importacao_id', $novo->id)->count());
        $this->post(route('importacoes.enviar', $novo))->assertStatus(422); // nada para enviar
    }

    public function test_leitura_nao_reprocessa_nem_edita_o_lote(): void
    {
        $this->creditos(10);
        $original = $this->loteEnviado(1);
        $leitor = User::factory()->create(['conta_atual_id' => $this->conta->id]);
        $this->conta->usuarios()->attach($leitor->id, ['papel' => Papel::Leitura->value]);

        $this->actingAs($leitor)->post(route('importacoes.reprocessar', $original))->assertForbidden();
        $this->actingAs($leitor)->putJson(route('importacoes.linhas.salvar', $original), ['linhas' => []])->assertForbidden();
    }

    public function test_leitura_nao_pode_enviar_nem_corrigir(): void
    {
        $this->creditos(5);
        $imp = $this->importar(1);
        $leitor = User::factory()->create(['conta_atual_id' => $this->conta->id]);
        $this->conta->usuarios()->attach($leitor->id, ['papel' => Papel::Leitura->value]);
        $nota = NotaFiscal::semIsolamento()->where('status', StatusNota::Invalida)->firstOrFail();

        $this->actingAs($leitor)->post(route('importacoes.enviar', $imp))->assertForbidden();
        $this->actingAs($leitor)->patch(route('notas.corrigir', $nota), ['tomador_documento' => '52998224725'])->assertForbidden();
    }
}
