<?php

namespace Tests\Feature;

use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Services\Nfse\DpsBuilder;
use App\Services\Nfse\ValidadorXsd;
use Tests\TestCase;

/** A DPS que o DpsBuilder monta tem que passar no XSD oficial vigente (evita E1235 na SEFIN). */
class DpsXsdTest extends TestCase
{
    private function nota(array $nota = [], array $emitente = []): NotaFiscal
    {
        $emit = new Emitente($emitente + [
            'cnpj' => '11222333000181', 'codigo_municipio' => '3549904',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0,
            'aliquota_simples' => 6, 'ambiente' => 2,
        ]);

        $n = new NotaFiscal($nota + [
            'serie_dps' => '00001', 'numero_dps' => 26,
            'tomador_documento' => '19566539000130', 'tomador_nome' => 'Malix Automóveis Ltda – EPP',
            'tomador_email' => 'teste@teste.com.br', 'codigo_servico' => '010701',
            'descricao' => 'Manutenção de programas de computação.', 'valor' => 452.50,
            'data_competencia' => '2026-11-05',
        ]);
        $n->setRelation('emitente', $emit);

        return $n;
    }

    public function test_dps_sem_nbs_passa_no_xsd(): void
    {
        $xml = (new DpsBuilder)->montar($this->nota());

        $this->assertStringContainsString('<finNFSe>0</finNFSe>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));
    }

    public function test_dps_com_nbs_passa_no_xsd(): void
    {
        $xml = (new DpsBuilder)->montar($this->nota(['codigo_nbs' => '115022000']));

        $this->assertSame([], (new ValidadorXsd)->erros($xml));
    }

    public function test_dps_de_mei_e_nao_optante_passam_no_xsd(): void
    {
        foreach ([2, 1] as $regime) {
            $xml = (new DpsBuilder)->montar($this->nota([], [
                'op_simples_nacional' => $regime,
                'tributos_aprox' => ['federal' => 10, 'estadual' => 0, 'municipal' => 5],
            ]));

            $this->assertSame([], (new ValidadorXsd)->erros($xml), "regime {$regime}");
        }
    }

    public function test_nao_optante_leva_tributacao_federal_do_portal_e_os_demais_nao(): void
    {
        foreach ([1 => true, 2 => false, 3 => false] as $regime => $leva) {
            $xml = (new DpsBuilder)->montar($this->nota([], [
                'op_simples_nacional' => $regime,
                'tributos_aprox' => ['federal' => 13.45, 'estadual' => 0, 'municipal' => 2.70],
            ]));

            $this->assertSame($leva, str_contains($xml, '<tribFed><piscofins><CST>00</CST><tpRetPisCofins>0</tpRetPisCofins></piscofins></tribFed>'), "regime {$regime}");
            $this->assertStringNotContainsString('vRetIRRF', $xml);
            $this->assertStringNotContainsString('vRetCP', $xml);
            $this->assertStringNotContainsString('vRetCSLL', $xml);
            $this->assertSame([], (new ValidadorXsd)->erros($xml), "regime {$regime}");
        }

        $xml = (new DpsBuilder)->montar($this->nota([], ['op_simples_nacional' => 1, 'tributos_aprox' => ['federal' => 13.45, 'estadual' => 0, 'municipal' => 2.70]]));
        $this->assertStringContainsString('<pTotTribFed>13.45</pTotTribFed><pTotTribEst>0.00</pTotTribEst><pTotTribMun>2.70</pTotTribMun>', $xml);
    }
}
