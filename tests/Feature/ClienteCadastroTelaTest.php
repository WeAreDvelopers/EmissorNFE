<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\Tomador;
use App\Models\User;
use App\Services\ConsultaCep;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cadastro de cliente pela tela (além da planilha) e preenchimento do endereço pelo CEP. */
class ClienteCadastroTelaTest extends TestCase
{
    use RefreshDatabase;

    private Conta $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo']])]);

        $user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($user->id, ['papel' => Papel::Dono->value]);
        $user->forceFill(['conta_atual_id' => $this->conta->id])->save();
        $this->actingAs($user);
    }

    private function dados(array $extra = []): array
    {
        return $extra + [
            'documento' => '19.566.539/0001-30', 'nome' => 'Malix Automóveis Ltda', 'cep' => '01310-100',
            'logradouro' => 'Avenida Paulista', 'numero' => '1578', 'bairro' => 'Bela Vista', 'municipio' => 'São Paulo', 'uf' => 'sp',
            'emails' => 'Fin@malix.com.br, fin@malix.com.br',
        ];
    }

    public function test_tela_de_cadastro_abre_com_cep_antes_do_logradouro(): void
    {
        $html = $this->get(route('tomadores.criar'))->assertOk()->getContent();

        $this->assertStringContainsString('CPF ou CNPJ', $html);
        $this->assertStringContainsString('x-mascara="doc"', $html);
        $this->assertStringContainsString('x-mascara="cep"', $html);
        $ordem = array_map(fn ($c) => strpos($html, 'name="'.$c.'"'), ['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'municipio', 'uf']);
        $this->assertSame($ordem, collect($ordem)->sort()->values()->all(), 'campos fora da ordem lógica');
        $this->assertNotContains(false, $ordem);
        $this->get(route('tomadores.index'))->assertSee('Novo tomador');
    }

    public function test_cadastra_cliente_completo_normalizando_os_campos(): void
    {
        $this->post(route('tomadores.store'), $this->dados())->assertRedirect(route('tomadores.index'))->assertSessionHas('ok');

        $t = Tomador::where('documento', '19566539000130')->firstOrFail();
        $this->assertSame($this->conta->id, $t->conta_id);
        $this->assertSame('01310100', $t->cep);
        $this->assertSame('SP', $t->uf);
        $this->assertSame('3550308', $t->codigo_municipio);
        $this->assertSame(['fin@malix.com.br'], $t->emails);
        $this->assertFalse($t->pendente);
    }

    public function test_endereco_e_obrigatorio_no_cadastro_pela_tela(): void
    {
        $this->post(route('tomadores.store'), ['documento' => '19566539000130', 'nome' => 'Malix'])
            ->assertSessionHasErrors(['cep', 'logradouro', 'numero', 'bairro', 'municipio', 'uf']);
        $this->assertSame(0, Tomador::count());

        // complemento continua opcional
        $this->post(route('tomadores.store'), $this->dados())->assertSessionHasNoErrors();
        $this->assertSame(1, Tomador::count());
    }

    public function test_recusa_documento_invalido_duplicado_e_email_ruim(): void
    {
        $this->post(route('tomadores.store'), $this->dados(['documento' => '19566539000131']))->assertSessionHasErrors('documento');
        $this->post(route('tomadores.store'), $this->dados(['documento' => '123']))->assertSessionHasErrors('documento');
        $this->post(route('tomadores.store'), $this->dados(['emails' => 'sem-arroba']))->assertSessionHasErrors('emails');
        $this->post(route('tomadores.store'), $this->dados(['nome' => '']))->assertSessionHasErrors('nome');
        $this->assertSame(0, Tomador::count());

        $this->post(route('tomadores.store'), $this->dados());
        $this->post(route('tomadores.store'), $this->dados(['nome' => 'Outro nome']))->assertSessionHasErrors('documento');
        $this->assertSame(1, Tomador::count());
    }

    public function test_apenas_quem_pode_emitir_cadastra(): void
    {
        $leitor = User::factory()->create();
        $this->conta->usuarios()->attach($leitor->id, ['papel' => Papel::Leitura->value]);
        $leitor->forceFill(['conta_atual_id' => $this->conta->id])->save();

        $this->actingAs($leitor)->get(route('tomadores.criar'))->assertForbidden();
        $this->post(route('tomadores.store'), $this->dados())->assertForbidden();
    }

    public function test_cep_preenche_o_endereco_pelo_viacep(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response([
            'cep' => '01310-100', 'logradouro' => 'Avenida Paulista', 'bairro' => 'Bela Vista', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
        ])]);

        $this->getJson(route('cep.buscar', '01310-100'))->assertOk()->assertJson([
            'cep' => '01310100', 'logradouro' => 'Avenida Paulista', 'bairro' => 'Bela Vista', 'municipio' => 'São Paulo', 'uf' => 'SP', 'codigo_municipio' => '3550308',
        ]);

        // segunda consulta vem do cache: não chama a API de novo
        $this->getJson(route('cep.buscar', '01310100'))->assertOk();
        Http::assertSentCount(1);
    }

    public function test_se_o_viacep_cai_usa_a_brasilapi(): void
    {
        Http::fake([
            'viacep.com.br/*'    => Http::response('', 503),
            'brasilapi.com.br/*' => Http::response(['cep' => '01310100', 'street' => 'Avenida Paulista', 'neighborhood' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP']),
        ]);

        $this->getJson(route('cep.buscar', '01310100'))->assertOk()->assertJson(['logradouro' => 'Avenida Paulista', 'uf' => 'SP', 'codigo_municipio' => '3550308']);
    }

    public function test_cep_inexistente_ou_servicos_fora_do_ar_devolvem_404_sem_guardar_cache(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => true]), 'brasilapi.com.br/*' => Http::response(['message' => 'não encontrado'], 404)]);

        $this->getJson(route('cep.buscar', '99999999'))->assertNotFound();
        $this->assertNull(Cache::get('cep:99999999'));
        $this->assertNull((new ConsultaCep)->buscar('123'));
        $this->getJson('/cep/abc')->assertNotFound();   // fora do padrão da rota
    }

    private function notaDoCliente(Tomador $t, StatusNota $status): NotaFiscal
    {
        $emitente = Emitente::first() ?? Emitente::create([
            'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
        ]);

        return NotaFiscal::create([
            'emitente_id' => $emitente->id, 'tomador_id' => $t->id, 'tomador_documento' => $t->documento, 'tomador_nome' => $t->nome,
            'serie_dps' => '00001', 'numero_dps' => NotaFiscal::count() + 1, 'ambiente' => 2, 'status' => $status->value,
            'codigo_servico' => '010701', 'descricao' => 'Teste', 'valor' => 10, 'data_competencia' => '2026-11-05',
        ]);
    }

    public function test_notas_com_erro_mostram_o_erro_e_o_caminho_para_corrigir_mesmo_sem_lote_de_importacao(): void
    {
        $this->post(route('tomadores.store'), $this->dados());
        $t = Tomador::firstOrFail();
        $invalida = $this->notaDoCliente($t, StatusNota::Invalida);       // nota emitida à mão: sem importação
        $rejeitada = $this->notaDoCliente($t, StatusNota::Rejeitada);
        $rejeitada->update(['erros' => ['E0310 - Teste']]);

        $r = $this->get(route('notas.index'))->assertOk();
        $r->assertSee(route('pendencias', ['nota' => $invalida->id]), false)
            ->assertSee(route('pendencias', ['nota' => $rejeitada->id]), false)
            ->assertSee('Ver erro e corrigir');

        // o painel de correção abre para a nota selecionada
        $this->get(route('pendencias', ['nota' => $rejeitada->id]))->assertOk();
    }

    public function test_lista_tem_editar_e_liga_desliga_no_lugar_da_lixeira(): void
    {
        $this->post(route('tomadores.store'), $this->dados());
        $t = Tomador::firstOrFail();

        $this->get(route('tomadores.index'))->assertOk()->assertSee('Ações')
            ->assertSee(route('tomadores.editar', $t), false)
            ->assertSee(route('tomadores.situacao', $t), false)
            ->assertDontSee('data-acao-url', false);
    }

    public function test_inativa_e_reativa_o_cadastro_sem_excluir_e_registra_historico(): void
    {
        $this->post(route('tomadores.store'), $this->dados());
        $t = Tomador::firstOrFail();
        $this->assertTrue($t->ativo);

        $this->post(route('tomadores.situacao', $t))->assertRedirect()->assertSessionHas('ok');
        $this->assertFalse($t->fresh()->ativo);
        $this->assertDatabaseHas('auditoria', ['acao' => 'cliente_inativado']);
        $this->get(route('tomadores.index'))->assertSee('inativo');
        $this->getJson(route('tomadores.buscar', ['q' => 'Malix']))->assertOk()->assertJsonCount(0);   // inativo não aparece ao emitir

        $this->post(route('tomadores.situacao', $t))->assertRedirect();
        $this->assertTrue($t->fresh()->ativo);
        $this->getJson(route('tomadores.buscar', ['q' => 'Malix']))->assertJsonCount(1);
        $this->assertSame(2, $t->alteracoes()->count());
    }

    public function test_ativo_inativo_pelo_formulario_e_so_quem_pode_emitir_altera(): void
    {
        $this->post(route('tomadores.store'), $this->dados(['ativo' => '0']));
        $t = Tomador::firstOrFail();
        $this->assertFalse($t->ativo);

        $leitor = User::factory()->create();
        $this->conta->usuarios()->attach($leitor->id, ['papel' => Papel::Leitura->value]);
        $leitor->forceFill(['conta_atual_id' => $this->conta->id])->save();
        $this->actingAs($leitor)->post(route('tomadores.situacao', $t))->assertForbidden();
        $this->assertFalse($t->fresh()->ativo);
    }

    public function test_atualizar_cadastro_sem_codigo_ibge_resolve_pelo_cep_e_sai_de_pendente(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo']]),
            'viacep.com.br/*' => Http::response(['cep' => '12220-000', 'logradouro' => 'Rua X', 'bairro' => 'Centro', 'localidade' => 'São José dos Campos', 'uf' => 'SP', 'ibge' => '3549904']),
        ]);
        $t = Tomador::create([
            'conta_id' => $this->conta->id, 'documento' => '26824883000148', 'nome' => 'Gola Veiculos Ltda.', 'municipio' => 'S J dos Campos', 'uf' => 'SP',
            'cep' => '12220000', 'logradouro' => 'Rua X', 'numero' => '10', 'bairro' => 'Centro', 'pendente' => true,
        ]);

        $this->put(route('tomadores.update', $t), $this->dados(['nome' => 'Gola Veiculos Ltda.', 'municipio' => 'S J dos Campos', 'cep' => '12220-000', 'logradouro' => 'Rua X', 'numero' => '10', 'bairro' => 'Centro']))
            ->assertRedirect(route('tomadores.editar', $t))->assertSessionHas('confirmacao')->assertSessionHas('confirmacao_destino', route('tomadores.index'));

        $t->refresh();
        $this->assertSame('3549904', $t->codigo_municipio);
        $this->assertFalse($t->pendente);
        $this->get(route('tomadores.editar', $t))->assertOk()->assertSee('atualizado com sucesso')->assertDontSee('Cadastro incompleto')->assertSee('3549904');
    }

    public function test_codigo_ibge_escolhido_na_tela_vale_e_aparece_abaixo_do_municipio(): void
    {
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([]), 'viacep.com.br/*' => Http::response('', 503), 'brasilapi.com.br/*' => Http::response('', 503)]);   // servidor sem acesso ao IBGE

        $this->post(route('tomadores.store'), $this->dados(['codigo_municipio' => '3550308']))->assertSessionHasNoErrors();
        $t = Tomador::firstOrFail();
        $this->assertSame('3550308', $t->codigo_municipio);
        $this->assertFalse($t->pendente);

        $this->get(route('tomadores.editar', $t))->assertOk()->assertSee('Código IBGE', false)->assertSee('3550308');
        // salvar sem o código na requisição não apaga o que já está no cadastro
        $this->put(route('tomadores.update', $t), $this->dados())->assertSessionHasNoErrors();
        $this->assertSame('3550308', $t->fresh()->codigo_municipio);
    }

    public function test_lista_identifica_pf_e_pj_pelo_documento_e_filtra_por_tipo(): void
    {
        $this->post(route('tomadores.store'), $this->dados(['documento' => '829.202.149-34', 'nome' => 'Vaner Bertoluzzi']));
        // empresa (CNPJ) com nome de pessoa física: continua PJ, porque o tipo vem só do documento
        $this->post(route('tomadores.store'), $this->dados(['documento' => '26.824.883/0001-48', 'nome' => 'Marcelo Dias Cordeiro']));

        $this->assertSame('PF', Tomador::where('documento', '82920214934')->first()->tipoPessoa());
        $this->assertSame('PJ', Tomador::where('documento', '26824883000148')->first()->tipoPessoa());

        $this->get(route('tomadores.index'))->assertOk()->assertSee('Pessoa física (PF)')->assertSee('Vaner Bertoluzzi')->assertSee('Marcelo Dias Cordeiro');
        $this->get(route('tomadores.index', ['tipo' => 'pf']))->assertSee('Vaner Bertoluzzi')->assertDontSee('Marcelo Dias Cordeiro')->assertSee('Limpar filtros');
        $this->get(route('tomadores.index', ['tipo' => 'pj']))->assertSee('Marcelo Dias Cordeiro')->assertDontSee('Vaner Bertoluzzi');
    }

    public function test_codigo_ibge_vem_da_tabela_local_sem_chamar_nenhuma_api(): void
    {
        Http::fake(fn () => throw new \RuntimeException('não deve chamar a rede'));

        $this->assertSame('3550308', \App\Support\MunicipiosIbge::codigo('SÃO PAULO', 'sp'));
        $this->assertSame('3549904', \App\Support\MunicipiosIbge::codigo('São José dos Campos', 'SP'));
        $this->assertSame('3304557', \App\Support\MunicipiosIbge::codigo('Rio de Janeiro', 'RJ'));
        $this->assertNull(\App\Support\MunicipiosIbge::codigo('Cidadelandia', 'RJ'));
        $this->assertSame('São Paulo/SP', \App\Support\MunicipiosIbge::rotulo('3550308'));
        $this->assertNull(\App\Support\MunicipiosIbge::rotulo('1234567'));
    }

    public function test_tela_de_edicao_segue_funcionando_com_o_formulario_compartilhado(): void
    {
        $this->post(route('tomadores.store'), $this->dados());
        $t = Tomador::firstOrFail();

        $this->get(route('tomadores.editar', $t))->assertOk()->assertSee('Malix Automóveis Ltda')->assertSee('01310100')->assertDontSee('CPF ou CNPJ');
        $this->put(route('tomadores.update', $t), $this->dados(['nome' => 'Malix Novo', 'emails' => 'x@x.com']))->assertRedirect(route('tomadores.editar', $t));
        $this->assertSame('Malix Novo', $t->fresh()->nome);
    }
}
