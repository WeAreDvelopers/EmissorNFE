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
use App\Models\Tomador;
use App\Models\TomadorAlteracao;
use App\Models\User;
use App\Services\Nfse\DpsBuilder;
use App\Services\Nfse\ValidadorXsd;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeraPlanilha;
use Tests\TestCase;

/** Planilha "Notas ERP": cadastro de clientes atualizado só com "x" e só ao confirmar, sem destinatário, totais. */
class TomadoresImportacaoTest extends TestCase
{
    use RefreshDatabase;

    private const MALIX = '19566539000130';
    private const CEMAR = '07445371000126';
    private const MIA = '16182543833';
    private const GOLDEN = '69057368000192';

    private User $user;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        GeraPlanilha::$clienteCompleto = false;   // aqui o cadastro do cliente é o assunto: cada teste informa o que quer
        Storage::fake('local');
        Queue::fake([EmitirNfseJob::class]);
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([
            ['id' => 3550308, 'nome' => 'São Paulo'], ['id' => 3538907, 'nome' => 'Piedade'], ['id' => 3547809, 'nome' => 'Santo André'],
        ])]);

        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
                'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'Manutenção de programas de computação.',
            ]);
        });
    }

    private function importar(array $linhas, array $opcoes = []): Importacao
    {
        $this->actingAs($this->user)->post(route('importacoes.store'), [
            'emitente_id' => $this->emitente->id, 'planilha' => GeraPlanilha::xlsx($this->emitente->cnpj, $linhas, $opcoes),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Importacao::latest('id')->firstOrFail();
    }

    private function confirmar(Importacao $imp): void
    {
        $this->post(route('importacoes.enviar', $imp), ['modo' => 'enviar_prontas'])->assertRedirect();
    }

    private function cliente(string $doc): ?Tomador
    {
        return Tomador::semIsolamento()->where('documento', $doc)->first();
    }

    // ---------- cadastro novo e prévia ----------

    public function test_cliente_novo_sem_x_usa_o_nome_de_referencia_e_so_e_criado_ao_confirmar(): void
    {
        $imp = $this->importar([['doc' => self::MALIX, 'fantasia_ref' => 'Multicar', 'nome_ref' => 'Malix Automóveis Ltda – EPP', 'valor' => 452.5]]);

        $nota = NotaFiscal::semIsolamento()->firstOrFail();
        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertSame('Malix Automóveis Ltda – EPP', $nota->tomador_nome);
        $this->assertSame('010302', $nota->codigo_servico);                       // padrão da empresa
        $this->assertSame('Manutenção de programas de computação.', $nota->descricao);
        $this->assertNull($this->cliente(self::MALIX), 'nada é gravado antes da confirmação');

        $this->get(route('importacoes.conferir', $imp))->assertOk()
            ->assertSee('cliente novo')->assertSee('Multicar')->assertSee('Nada foi gravado ainda');

        $this->confirmar($imp);

        $c = $this->cliente(self::MALIX);
        $this->assertSame('Malix Automóveis Ltda – EPP', $c->nome);
        $this->assertSame('Multicar', $c->nome_fantasia);
        $this->assertTrue($c->pendente, 'sem endereço: pendente de correção');
        $this->assertSame($c->id, $nota->fresh()->tomador_id);
        $this->assertSame(1, TomadorAlteracao::semIsolamento()->where('tomador_id', $c->id)->where('origem', 'importacao')->count());
    }

    public function test_so_a_linha_com_x_atualiza_e_campo_vazio_preserva_o_que_existe(): void
    {
        app(ContaAtual::class)->como($this->conta->id, fn () => Tomador::create([
            'emitente_id' => $this->emitente->id, 'documento' => self::CEMAR, 'nome' => 'Cemar Antiga', 'nome_fantasia' => 'Racing', 'logradouro' => 'Rua Velha', 'numero' => '1',
            'bairro' => 'Centro', 'municipio' => 'São Paulo', 'uf' => 'SP', 'codigo_municipio' => '3550308', 'cep' => '01001000', 'emails' => ['velho@x.com'],
        ]));

        // sem x: o endereço novo é ignorado
        $semX = $this->importar([['doc' => self::CEMAR, 'nome_ref' => 'Cemar', 'logradouro' => 'Rua Nova', 'valor' => 100]]);
        $this->confirmar($semX);
        $this->assertSame('Rua Velha', $this->cliente(self::CEMAR)->logradouro);

        // com x: muda só o que veio preenchido (e-mails e razão social), o resto fica
        $comX = $this->importar([['doc' => self::CEMAR, 'atualizar' => true, 'nome' => 'Cemar Multimarcas Eireli', 'emails' => 'a@x.com , b@x.com, A@x.com', 'valor' => 120]]);
        $this->get(route('importacoes.conferir', $comX))->assertOk()->assertSee('Cemar Antiga')->assertSee('Cemar Multimarcas Eireli')->assertSee('a@x.com, b@x.com');
        $this->assertSame('Cemar Antiga', $this->cliente(self::CEMAR)->nome, 'ainda não confirmou');

        $this->confirmar($comX);

        $c = $this->cliente(self::CEMAR);
        $this->assertSame('Cemar Multimarcas Eireli', $c->nome);
        $this->assertSame(['a@x.com', 'b@x.com'], $c->emails);                    // sem duplicar
        $this->assertSame('Rua Velha', $c->logradouro);                           // vazio na planilha: preservado
        $this->assertSame('Racing', $c->nome_fantasia);

        $historico = TomadorAlteracao::semIsolamento()->where('tomador_id', $c->id)->latest('id')->first();
        $this->assertSame(['Cemar Antiga', 'Cemar Multimarcas Eireli'], $historico->campos['nome']);
    }

    public function test_nota_leva_o_email_principal_e_o_aviso_vai_para_todos(): void
    {
        $imp = $this->importar([['doc' => self::CEMAR, 'nome_ref' => 'Cemar', 'atualizar' => true, 'emails' => 'um@x.com, dois@x.com', 'valor' => 100]]);
        $this->confirmar($imp);

        $nota = NotaFiscal::semIsolamento()->firstOrFail();
        $this->assertSame('um@x.com', $nota->tomador_email);
        $this->assertSame(['um@x.com', 'dois@x.com'], \App\Http\Controllers\NotaDocumentoController::destinatarios($nota->load('tomador')));
    }

    public function test_endereco_novo_resolve_o_municipio_no_ibge_e_completa_o_cadastro(): void
    {
        $imp = $this->importar([['doc' => self::MIA, 'nome_ref' => 'Geremias', 'atualizar' => true, 'logradouro' => 'Av. Leonel', 'numero' => '1110',
            'bairro' => 'Centro', 'municipio' => 'Piedade', 'uf' => 'SP', 'cep' => '18170-005', 'emails' => 'mia@x.com', 'valor' => 350]]);
        $this->confirmar($imp);

        $c = $this->cliente(self::MIA);
        $this->assertSame('3537800', $c->codigo_municipio);   // tabela oficial do IBGE (local)
        $this->assertSame('18170005', $c->cep);
        $this->assertFalse($c->pendente);
    }

    // ---------- sem destinatário ----------

    public function test_sem_destinatario_nao_cria_cliente_e_a_dps_sai_sem_o_grupo_toma(): void
    {
        $imp = $this->importar([['doc' => 'sem destinatário informado', 'valor' => 1000]]);

        $nota = NotaFiscal::semIsolamento()->firstOrFail();
        $this->assertTrue($nota->sem_tomador);
        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertSame('Sem destinatário informado', $nota->tomador_rotulo);

        $this->confirmar($imp);
        $this->assertSame(0, Tomador::semIsolamento()->count());

        $nota->numero_dps = 1;
        $nota->setRelation('emitente', $this->emitente);
        $xml = (new DpsBuilder)->montar($nota);
        $this->assertStringNotContainsString('<toma>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));
    }

    // ---------- DPS com endereço ----------

    public function test_dps_leva_o_endereco_do_cliente_quando_completo_e_passa_no_xsd(): void
    {
        $imp = $this->importar([['doc' => self::GOLDEN, 'nome_ref' => 'Golden Thor', 'atualizar' => true, 'logradouro' => 'Av. Pres. Tancredo Neves', 'numero' => '600',
            'complemento' => 'Lj 223', 'bairro' => 'Vila Nancy', 'municipio' => 'São Paulo', 'uf' => 'SP', 'cep' => '04287-010', 'emails' => 'g@x.com', 'valor' => 350]]);
        $this->confirmar($imp);

        $nota = NotaFiscal::semIsolamento()->with('tomador')->firstOrFail();
        $nota->numero_dps = 7;
        $nota->setRelation('emitente', $this->emitente);
        $xml = (new DpsBuilder)->montar($nota);

        $this->assertStringContainsString('<cMun>3550308</cMun>', $xml);
        $this->assertStringContainsString('<CEP>04287010</CEP>', $xml);
        $this->assertStringContainsString('<xCpl>Lj 223</xCpl>', $xml);
        $this->assertStringContainsString('<email>g@x.com</email>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));
    }

    public function test_cliente_pendente_nao_emite_e_a_dps_nunca_leva_endereco_incompleto(): void
    {
        $this->confirmar($this->importar([['doc' => self::MALIX, 'nome_ref' => 'Malix', 'valor' => 452.5]]));

        $nota = NotaFiscal::semIsolamento()->with('tomador')->firstOrFail();
        // Regra do negócio: cliente sem endereço completo e e-mail não recebe nota (fica à espera do cadastro)
        $this->assertSame(StatusNota::Invalida, $nota->status);
        $this->assertStringContainsString('Cadastro do cliente incompleto', $nota->erros[0]);
        Queue::assertNothingPushed();

        $nota->numero_dps = 3;
        $nota->setRelation('emitente', $this->emitente);
        $xml = (new DpsBuilder)->montar($nota);

        $this->assertStringNotContainsString('<end>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));
    }

    // ---------- totais ----------

    public function test_total_de_e1_diferente_da_soma_gera_aviso_na_conferencia(): void
    {
        $imp = $this->importar([['doc' => self::MALIX, 'nome_ref' => 'Malix', 'valor' => 100], ['doc' => self::CEMAR, 'nome_ref' => 'Cemar', 'valor' => 200]], ['total' => 999]);

        $this->assertNotEmpty($imp->avisos);
        $this->get(route('importacoes.conferir', $imp))->assertOk()->assertSee('Confira antes de enviar')->assertSee('(E1)')->assertSee('R$ 999,00');
    }

    public function test_totais_que_batem_nao_geram_aviso(): void
    {
        $imp = $this->importar([['doc' => self::MALIX, 'nome_ref' => 'Malix', 'valor' => 100], ['doc' => self::CEMAR, 'nome_ref' => 'Cemar', 'valor' => 200]]);

        $this->assertEmpty($imp->avisos);
    }

    // ---------- abas ----------

    public function test_ignora_a_aba_de_teste_e_acha_a_aba_do_mes(): void
    {
        $this->importar([['doc' => self::MALIX, 'nome_ref' => 'Malix', 'valor' => 100]], ['aba' => 'Dezembro', 'aba_extra' => 'teste modelo rafa']);

        $this->assertSame(1, NotaFiscal::semIsolamento()->count());
    }

    public function test_modelo_baixado_vem_com_o_cnpj_da_empresa_em_a1_e_e_aceito_pelo_leitor(): void
    {
        $resposta = $this->actingAs($this->user)->get(route('importacoes.modelo', ['emitente' => $this->emitente->id]))->assertOk();
        $arquivo = tempnam(sys_get_temp_dir(), 'md').'.xlsx';
        file_put_contents($arquivo, $resposta->streamedContent());

        $lido = \App\Support\PlanilhaModelo::ler($arquivo);
        $this->assertSame('19534920000118', $lido['cnpj']);
        $this->assertSame(1800.0, $lido['total_valor'], 'E1 é a soma das linhas de exemplo');
        $this->assertCount(2, $lido['linhas']);
        $this->assertTrue($lido['linhas'][1]['sem_tomador']);
        unlink($arquivo);
    }

    // ---------- tela de clientes ----------

    public function test_tela_de_clientes_lista_edita_e_mostra_o_historico(): void
    {
        $this->confirmar($this->importar([['doc' => self::MALIX, 'fantasia_ref' => 'Multicar', 'nome_ref' => 'Malix Ltda', 'valor' => 100]]));
        $c = $this->cliente(self::MALIX);

        $this->get(route('tomadores.index'))->assertOk()->assertSee('Malix Ltda')->assertSee('pendente de correção');
        $this->get(route('tomadores.index', ['q' => '19.566.539']))->assertOk()->assertSee('Malix Ltda');
        $this->get(route('tomadores.index', ['q' => 'inexistente']))->assertOk()->assertDontSee('Malix Ltda');

        $this->put(route('tomadores.update', $c), [
            'nome' => 'Malix Automóveis', 'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'municipio' => 'São Paulo', 'uf' => 'sp',
            'cep' => '01001-000', 'emails' => 'x@x.com, ruim',
        ])->assertSessionHasErrors('emails');

        $this->put(route('tomadores.update', $c), [
            'nome' => 'Malix Automóveis', 'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'municipio' => 'São Paulo', 'uf' => 'sp',
            'cep' => '01001-000', 'emails' => 'x@x.com',
        ])->assertRedirect(route('tomadores.editar', $c));

        $c->refresh();
        $this->assertSame('SP', $c->uf);
        $this->assertSame('3550308', $c->codigo_municipio);
        $this->assertFalse($c->pendente);
        $this->get(route('tomadores.editar', $c))->assertOk()->assertSee('Malix Ltda')->assertSee('edição manual');
    }

    public function test_cliente_de_outra_conta_nao_aparece_nem_abre(): void
    {
        $outra = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Profitcar', 'documento' => '11444777000161', 'email_financeiro' => 'p@x.com']);
        $estranho = app(ContaAtual::class)->como($outra->id, fn () => Tomador::create(['documento' => '52998224725', 'nome' => 'Cliente Alheio']));

        $this->actingAs($this->user)->get(route('tomadores.index'))->assertOk()->assertDontSee('Cliente Alheio');
        $this->get(route('tomadores.editar', $estranho->id))->assertNotFound();
        $this->put(route('tomadores.update', $estranho->id), ['nome' => 'Invadido'])->assertNotFound();
        $this->assertSame('Cliente Alheio', $estranho->fresh()->nome);
    }

    public function test_o_mesmo_documento_pode_existir_em_contas_diferentes(): void
    {
        $outra = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Profitcar', 'documento' => '11444777000161', 'email_financeiro' => 'p@x.com']);
        app(ContaAtual::class)->como($outra->id, fn () => Tomador::create(['documento' => self::MALIX, 'nome' => 'Malix da outra']));

        $this->confirmar($this->importar([['doc' => self::MALIX, 'nome_ref' => 'Malix da Moneycar', 'valor' => 100]]));

        $this->assertSame(2, Tomador::semIsolamento()->where('documento', self::MALIX)->count());
        $this->assertSame('Malix da Moneycar', Tomador::semIsolamento()->where('conta_id', $this->conta->id)->first()->nome);
    }

    public function test_confirmar_duas_vezes_nao_duplica_historico(): void
    {
        $imp = $this->importar([['doc' => self::MALIX, 'nome_ref' => 'Malix', 'valor' => 100]]);
        $this->confirmar($imp);
        app(\App\Services\Tomadores\CadastroTomadores::class)->aplicar($imp->fresh(), null);

        $this->assertSame(1, TomadorAlteracao::semIsolamento()->count());
    }
}
