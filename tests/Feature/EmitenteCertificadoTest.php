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

    public function test_abre_certificado_com_criptografia_legada_e_guarda_no_formato_moderno(): void
    {
        $dir = sys_get_temp_dir().'/pfxlegado-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $chave = openssl_pkey_new(['private_key_bits' => 2048]);
            $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'PADARIA TESTE LTDA:00000000000191'], $chave), null, $chave, 365);
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($chave, $chavePem);
            file_put_contents("$dir/c.pem", $certPem);
            file_put_contents("$dir/k.pem", $chavePem);

            $base = ['-export', '-inkey', "$dir/k.pem", '-in', "$dir/c.pem", '-out', "$dir/legado.pfx",
                '-passout', 'pass:segredo', '-certpbe', 'PBE-SHA1-RC2-40', '-keypbe', 'PBE-SHA1-3DES'];
            $gerou = false;
            foreach ([['-legacy'], []] as $extra) { // OpenSSL 1.1 não tem -legacy
                $p = new \Symfony\Component\Process\Process(['openssl', 'pkcs12', ...$extra, ...$base]);
                if ($p->run() === 0) {
                    $gerou = true;
                    break;
                }
            }
            if (! $gerou) {
                $this->markTestSkipped('openssl CLI não gera .pfx legado aqui.');
            }
            $legado = file_get_contents("$dir/legado.pfx");
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }

        $c = new \App\Services\Nfse\Certificado($legado, 'segredo');

        $this->assertSame('00000000000191', $c->cnpjProvavel());
        $this->assertTrue(openssl_pkcs12_read($c->pfx(), $out, 'segredo'), 'o .pfx guardado deve abrir direto no PHP');

        $this->expectException(\RuntimeException::class);
        new \App\Services\Nfse\Certificado($legado, 'senha-errada');
    }

    public function test_certificado_de_outro_cnpj_volta_com_erro_e_a_tela_renderiza_mantendo_o_codigo_de_servico(): void
    {
        $r = $this->actingAs($this->usuarioDono())->followingRedirects()
            ->from(route('emitentes.criar'))
            ->post(route('emitentes.store'), [
                'cnpj' => '43.559.579/0001-30', 'razao_social' => 'PROFITCAR LTDA', 'codigo_municipio' => '3549904',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => '6', 'regime_especial' => 0,
                'tributos_aprox' => ['federal' => '', 'estadual' => '', 'municipal' => ''],
                'serie_dps' => '1', 'ambiente' => 2, 'codigo_tributacao_municipal' => '001', 'codigo_servico_padrao' => '010701',
                'certificado' => UploadedFile::fake()->createWithContent('c.pfx', $this->pfx('segredo')), 'certificado_senha' => 'segredo',
            ]);
        $r->assertOk()->assertSee('não do emitente')->assertSee('010701');
        $this->assertDatabaseMissing('emitentes', ['cnpj' => '43559579000130']);
    }

    public function test_cnpj_repetido_na_conta_avisa_em_vez_de_dar_erro_500(): void
    {
        $dados = fn () => [
            'cnpj' => '00.000.000/0001-91', 'razao_social' => 'PADARIA TESTE LTDA', 'codigo_municipio' => '3549904',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => '6', 'regime_especial' => 0,
            'tributos_aprox' => ['federal' => '', 'estadual' => '', 'municipal' => ''],
            'serie_dps' => '1', 'ambiente' => 2, 'codigo_tributacao_municipal' => '001',
        ];
        $u = $this->usuarioDono();
        $this->actingAs($u)->post(route('emitentes.store'), $dados())->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('emitentes.store'), $dados())->assertSessionHasErrors('cnpj');
    }

    public function test_serie_70000_de_producao_e_aceita_e_salva_sem_certificado(): void
    {
        $dados = [
            'cnpj' => '00.000.000/0001-91', 'razao_social' => 'PADARIA TESTE LTDA', 'codigo_municipio' => '3549904',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => '6', 'regime_especial' => 0,
            'tributos_aprox' => ['federal' => '', 'estadual' => '', 'municipal' => ''],
            'serie_dps' => '70000', 'ambiente' => 2, 'codigo_tributacao_municipal' => '001',
        ];
        $this->actingAs($this->usuarioDono())->post(route('emitentes.store'), $dados)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('emitentes', ['cnpj' => '00000000000191', 'serie_dps' => '70000']);
        $this->get(route('inicio'))->assertSee('falta o certificado digital A1');
    }

    public function test_producao_exige_certificado_cadastrado(): void
    {
        $dados = [
            'cnpj' => '00.000.000/0001-91', 'razao_social' => 'PADARIA TESTE LTDA', 'codigo_municipio' => '3549904',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => '6', 'regime_especial' => 0,
            'tributos_aprox' => ['federal' => '', 'estadual' => '', 'municipal' => ''],
            'serie_dps' => '70000', 'ambiente' => 1, 'codigo_tributacao_municipal' => '001',
        ];
        $this->actingAs($this->usuarioDono())->post(route('emitentes.store'), $dados)->assertSessionHasErrors('ambiente');
    }

    public function test_historico_registra_o_primeiro_cadastro_e_as_alteracoes_com_antes_e_depois(): void
    {
        $dados = [
            'cnpj' => '00.000.000/0001-91', 'razao_social' => 'PADARIA TESTE LTDA', 'codigo_municipio' => '3549904',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => '6', 'regime_especial' => 0,
            'tributos_aprox' => ['federal' => '', 'estadual' => '', 'municipal' => ''],
            'serie_dps' => '1', 'ambiente' => 2, 'codigo_tributacao_municipal' => '001',
        ];
        $u = $this->usuarioDono();
        $this->actingAs($u)->post(route('emitentes.store'), $dados)->assertSessionHasNoErrors();
        $e = \App\Models\Emitente::firstOrFail();
        $this->assertSame('cadastro', $e->alteracoes()->first()->origem);

        $this->actingAs($u)->put(route('emitentes.update', $e), ['razao_social' => 'PADARIA NOVA LTDA'] + $dados)->assertSessionHasNoErrors();
        $this->assertSame(2, $e->alteracoes()->count());
        $ultima = $e->alteracoes()->first();
        $this->assertSame(['de' => 'PADARIA TESTE LTDA', 'para' => 'PADARIA NOVA LTDA'], $ultima->campos['razao_social']);
        $this->actingAs($u)->get(route('emitentes.editar', $e))->assertOk()->assertSee('Primeiro cadastro')->assertSee('PADARIA NOVA LTDA');
    }

    public function test_cadastra_empresa_me_epp_mesmo_com_campos_de_tributos_escondidos_vazios(): void
    {
        $this->actingAs($this->usuarioDono())
            ->post(route('emitentes.store'), [
                'cnpj' => '00.000.000/0001-91', 'razao_social' => 'PADARIA TESTE LTDA', 'codigo_municipio' => '3549904',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => '6', 'regime_especial' => 0,
                'tributos_aprox' => ['federal' => '', 'estadual' => '', 'municipal' => ''], // o navegador envia os escondidos
                'serie_dps' => '1', 'ambiente' => 2, 'codigo_tributacao_municipal' => '001',
                'certificado' => UploadedFile::fake()->createWithContent('c.pfx', $this->pfx('segredo')), 'certificado_senha' => 'segredo',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('emitentes', ['cnpj' => '00000000000191', 'op_simples_nacional' => 3, 'codigo_tributacao_municipal' => '001']);
    }

    public function test_formulario_da_empresa_renderiza_e_raiz_redireciona(): void
    {
        $this->actingAs($this->usuarioDono())->get(route('emitentes.criar'))->assertOk()
            ->assertSee('Ler certificado e preencher')->assertSee('Vamos cadastrar a sua empresa');

        $this->get('/')->assertRedirect(route('inicio'));
    }
}
