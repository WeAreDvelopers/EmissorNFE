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
        $this->get(route('tomadores.index'))->assertSee('Novo cliente');
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

    public function test_cadastro_sem_endereco_fica_pendente_e_avisa(): void
    {
        $this->post(route('tomadores.store'), ['documento' => '19566539000130', 'nome' => 'Malix'])
            ->assertRedirect(route('tomadores.index'))
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'endereço está incompleto'));

        $this->assertTrue(Tomador::firstOrFail()->pendente);
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

    public function test_lista_tem_coluna_de_acoes_com_editar_e_excluir(): void
    {
        $this->post(route('tomadores.store'), $this->dados());
        $t = Tomador::firstOrFail();

        $this->get(route('tomadores.index'))->assertOk()->assertSee('Ações')
            ->assertSee(route('tomadores.editar', $t), false)
            ->assertSee('data-acao-url="'.route('tomadores.excluir', $t).'"', false);
    }

    public function test_exclui_cliente_sem_nota_emitida_e_registra_na_auditoria(): void
    {
        $this->post(route('tomadores.store'), $this->dados());
        $t = Tomador::firstOrFail();
        $this->notaDoCliente($t, StatusNota::Rejeitada);   // nota que não saiu não impede

        $this->deleteJson(route('tomadores.excluir', $t))->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(0, Tomador::count());
        $this->assertNull(NotaFiscal::firstOrFail()->tomador_id);          // a nota guarda nome e documento
        $this->assertSame('Malix Automóveis Ltda', NotaFiscal::first()->tomador_nome);
        $this->assertDatabaseHas('auditoria', ['acao' => 'cliente_excluido']);
    }

    public function test_nao_exclui_cliente_com_nota_emitida_ou_cancelada(): void
    {
        foreach (['19566539000130' => StatusNota::Emitida, '07445371000126' => StatusNota::Cancelada] as $doc => $status) {
            $this->post(route('tomadores.store'), $this->dados(['documento' => $doc]));
            $t = Tomador::where('documento', $doc)->firstOrFail();
            $this->notaDoCliente($t, $status);

            $this->deleteJson(route('tomadores.excluir', $t))->assertStatus(422)
                ->assertJsonFragment(['message' => 'Não dá para excluir “'.$t->nome.'”: há 1 nota emitida para este cliente.']);
            $this->assertNotNull(Tomador::find($t->id), $status->value);
        }

        $this->get(route('tomadores.index'))->assertSee('Não dá para excluir: este cliente tem 1 nota emitida.', false);
    }

    public function test_so_quem_pode_emitir_exclui(): void
    {
        $this->post(route('tomadores.store'), $this->dados());
        $t = Tomador::firstOrFail();

        $leitor = User::factory()->create();
        $this->conta->usuarios()->attach($leitor->id, ['papel' => Papel::Leitura->value]);
        $leitor->forceFill(['conta_atual_id' => $this->conta->id])->save();

        $this->actingAs($leitor)->deleteJson(route('tomadores.excluir', $t))->assertForbidden();
        $this->assertNotNull(Tomador::find($t->id));
        $this->get(route('tomadores.index'))->assertDontSee('data-acao-url', false);
    }

    public function test_tela_de_edicao_segue_funcionando_com_o_formulario_compartilhado(): void
    {
        $this->post(route('tomadores.store'), $this->dados());
        $t = Tomador::firstOrFail();

        $this->get(route('tomadores.editar', $t))->assertOk()->assertSee('Malix Automóveis Ltda')->assertSee('01310100')->assertDontSee('CPF ou CNPJ');
        $this->put(route('tomadores.update', $t), ['nome' => 'Malix Novo', 'emails' => 'x@x.com'])->assertRedirect(route('tomadores.index'));
        $this->assertSame('Malix Novo', $t->fresh()->nome);
    }
}
