<?php

namespace Tests\Feature;

use App\Services\Nfse\Certificado;
use App\Services\Nfse\RejeicaoSefinException;
use App\Services\Nfse\SefinNacionalClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SefinErrosTest extends TestCase
{
    public function test_rejeicao_inclui_o_complemento_da_sefin(): void
    {
        Http::fake(['*' => Http::response(['erros' => [[
            'codigo'      => 'E1235',
            'descricao'   => 'Falha no esquema XML do DF-e.',
            'complemento' => 'Element infDPS: motivo detalhado',
        ]]], 400)]);

        $certificado = $this->createMock(Certificado::class);
        $certificado->method('arquivosPem')->willReturn(['cert' => '/dev/null', 'key' => '/dev/null']);

        try {
            (new SefinNacionalClient($certificado, 2))->emitir('<DPS/>');
            $this->fail('Deveria lançar RejeicaoSefinException');
        } catch (RejeicaoSefinException $e) {
            $this->assertSame(
                ['E1235 Falha no esquema XML do DF-e. — Element infDPS: motivo detalhado'],
                $e->erros
            );
        }
    }
}
