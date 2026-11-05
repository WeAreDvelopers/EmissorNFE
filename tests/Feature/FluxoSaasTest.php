<?php

namespace Tests\Feature;

use App\Models\Carteira;
use App\Models\Conta;
use App\Models\Pagamento;
use Database\Seeders\DevSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FluxoSaasTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastro_conta_emitente_e_compra_de_creditos(): void
    {
        $this->seed(DevSeeder::class); // pacotes + usuário de teste (a revenda padrão já vem do TestCase)

        // Cadastro → cai na criação de conta
        $this->post('/register', ['name' => 'Rafael', 'email' => 'r@x.com', 'password' => 'senha-forte-123', 'password_confirmation' => 'senha-forte-123']);
        $this->assertAuthenticated();
        $this->get('/dashboard')->assertRedirect(route('contas.criar'));
        $this->get(route('contas.criar'))->assertOk()->assertSee('Criar conta');

        // Cria a conta (CNPJ válido) e recebe o bônus de teste
        $this->post(route('contas.store'), ['nome' => 'Minha Empresa', 'documento' => '11.222.333/0001-81', 'email_financeiro' => 'fin@x.com'])
            ->assertRedirect(route('emitentes.criar'));
        $conta = Conta::firstOrFail();
        $this->assertSame(10, (int) Carteira::where('conta_id', $conta->id)->value('disponivel'));

        // Telas principais respondem
        foreach (['importacoes.index', 'emitentes.index', 'emitentes.criar', 'creditos.index', 'usuarios.index', 'importacoes.modelo'] as $rota) {
            $this->get(route($rota))->assertOk();
        }
        $this->get(route('creditos.index'))->assertSee('Inicial')->assertSee('10');

        // Compra via Pix fake → paga → crédito entra uma única vez
        $pacote = \App\Models\Pacote::where('nome', 'Inicial')->firstOrFail();
        $this->post(route('creditos.comprar', $pacote))->assertRedirect();
        $pag = Pagamento::semIsolamento()->firstOrFail();
        $this->get(route('pagamentos.show', $pag))->assertOk()->assertSee('<svg', false);

        $this->post(route('dev.pix.pagar', $pag))->assertSessionHas('ok');
        $this->post(route('dev.pix.pagar', $pag)); // repetido: não credita de novo
        $this->assertSame(60, (int) Carteira::where('conta_id', $conta->id)->value('disponivel'));
        $this->getJson(route('pagamentos.status', $pag))->assertJson(['status' => 'pago']);
    }
}
