<?php

namespace Tests\Feature;

use App\Services\Nfse\Certificado;
use App\Services\Nfse\RejeicaoSefinException;
use App\Services\Nfse\SefinNacionalClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SefinErrosTest extends TestCase
{
    private function cliente(): SefinNacionalClient
    {
        $certificado = $this->createMock(Certificado::class);
        $certificado->method('arquivosPem')->willReturn(['cert' => '/dev/null', 'key' => '/dev/null']);

        return new SefinNacionalClient($certificado, 2);
    }

    public function test_dps_ja_autorizada_traz_numero_e_xml_da_nfse(): void
    {
        $xml = '<NFSe><infNFSe><nNFSe>42</nNFSe></infNFSe></NFSe>';
        Http::fake([
            '*/dps/DPS123' => Http::response(['chaveAcesso' => 'CHAVE1'], 200),
            '*/nfse/CHAVE1' => Http::response(['chaveAcesso' => 'CHAVE1', 'nfseXmlGZipB64' => base64_encode(gzencode($xml))], 200),
        ]);

        $r = $this->cliente()->consultarPorDps('DPS123');

        $this->assertSame('CHAVE1', $r['chave_acesso']);
        $this->assertSame('42', $r['numero_nfse']);
        $this->assertSame($xml, $r['xml_nfse']);
    }

    public function test_dps_inexistente_na_sefin_devolve_null_e_nao_busca_nfse(): void
    {
        Http::fake(['*/dps/DPS999' => Http::response([], 404)]);

        $this->assertNull($this->cliente()->consultarPorDps('DPS999'));
        Http::assertSentCount(1);
    }

    public function test_consulta_sem_o_xml_ainda_devolve_a_chave(): void
    {
        Http::fake([
            '*/dps/DPS123' => Http::response(['chaveAcesso' => 'CHAVE1'], 200),
            '*/nfse/CHAVE1' => Http::response([], 500),
        ]);

        $this->assertSame(['chave_acesso' => 'CHAVE1'], $this->cliente()->consultarPorDps('DPS123'));
    }

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
