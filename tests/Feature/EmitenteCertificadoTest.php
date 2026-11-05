<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Conta;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EmitenteCertificadoTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Papel $papel = Papel::Dono): User
    {
        $conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Conta', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $user = User::factory()->create(['conta_atual_id' => $conta->id]);
        $conta->usuarios()->attach($user->id, ['papel' => $papel->value]);

        return $user;
    }

    /** Gera um .pfx de teste com CN no formato do e-CNPJ ("RAZÃO:CNPJ"). */
    private function pfx(string $senha, int $dias = 365): string
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'PADARIA TESTE LTDA:00000000000191'], $chave);
        $cert = openssl_csr_sign($csr, null, $chave, $dias);
        openssl_pkcs12_export($cert, $saida, $chave, $senha);

        return $saida;
    }

    public function test_le_certificado_e_devolve_dados_para_preencher_a_tela(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('cert.pfx', $this->pfx('segredo'));

        $this->actingAs($this->usuarioDono())
            ->postJson(route('emitentes.certificado.ler'), ['certificado' => $arquivo, 'certificado_senha' => 'segredo'])
            ->assertOk()
            ->assertJson(['razao_social' => 'PADARIA TESTE LTDA', 'cnpj' => '00.000.000/0001-91', 'vencido' => false]);
    }

    public function test_senha_errada_devolve_mensagem_amigavel(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('cert.pfx', $this->pfx('segredo'));

        $this->actingAs($this->usuarioDono())
            ->postJson(route('emitentes.certificado.ler'), ['certificado' => $arquivo, 'certificado_senha' => 'errada'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Confira a senha'));
    }

    public function test_so_dono_le_certificado(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('cert.pfx', $this->pfx('segredo'));

        $this->actingAs($this->usuarioDono(Papel::Operador))
            ->postJson(route('emitentes.certificado.ler'), ['certificado' => $arquivo, 'certificado_senha' => 'segredo'])
            ->assertForbidden();
    }

    public function test_formulario_da_empresa_renderiza_e_raiz_redireciona(): void
    {
        $this->actingAs($this->usuarioDono())->get(route('emitentes.criar'))->assertOk()
            ->assertSee('Ler certificado e preencher')->assertSee('Vamos cadastrar a sua empresa');

        $this->get('/')->assertRedirect(route('inicio'));
    }
}
