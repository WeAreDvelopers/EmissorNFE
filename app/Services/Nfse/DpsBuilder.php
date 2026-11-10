<?php

namespace App\Services\Nfse;

use App\Models\NotaFiscal;
use DOMDocument;
use DOMElement;

/**
 * Monta o XML da DPS (Declaração de Prestação de Serviço) — leiaute nacional.
 *
 * Conferido contra o XSD DPS v1.01 de 20/10/2026 (pacote PRODREST) e as regras do Anexo I v1.01 de 20/10/2026.
 * Cobre o caso comum: prestador CNPJ em município conveniado, tomador nacional,
 * ISSQN tributável sem retenção, sem deduções e sem grupo IBS/CBS.
 * Cada DPS é validada contra o XSD antes do envio (ValidadorXsd).
 */
class DpsBuilder
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    /**
     * Id da DPS: "DPS" + cód. município (7) + tipo inscrição (1: CPF, 2: CNPJ)
     * + inscrição (14) + série (5) + número (15) = 45 caracteres.
     */
    public function idDps(NotaFiscal $nota): string
    {
        $p = $nota->emitente->paraDps();

        return 'DPS'
            .str_pad($p['codigo_municipio'], 7, '0', STR_PAD_LEFT)
            .'2'
            .str_pad($p['cnpj'], 14, '0', STR_PAD_LEFT)
            .str_pad($nota->serie_dps, 5, '0', STR_PAD_LEFT)
            .str_pad((string) $nota->numero_dps, 15, '0', STR_PAD_LEFT);
    }

    public function montar(NotaFiscal $nota): string
    {
        // Cada CNPJ da conta tem seu regime, município e série. Na emissão manual com "todos os campos"
        // a nota pode trazer ajustes só dela (parametros_dps), que valem no lugar dos da empresa.
        $padraoEmpresa = $nota->emitente->paraDps();
        $ajustes = array_replace($this->padroesDaEmpresa($padraoEmpresa), $nota->parametros_dps ?? []);
        $p = array_replace($padraoEmpresa, array_intersect_key($ajustes, array_flip([
            'codigo_tributacao_municipal', 'regime_especial', 'aliquota_simples', 'tributos_aprox',
        ])));

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;

        $dps = $doc->createElementNS(self::NS, 'DPS');
        $dps->setAttribute('versao', config('nfse.versao_dps'));
        $doc->appendChild($dps);

        $inf = $this->el($doc, $dps, 'infDPS');
        $inf->setAttribute('Id', $this->idDps($nota));

        $this->el($doc, $inf, 'tpAmb', (string) $nota->emitente->ambiente);
        $this->el($doc, $inf, 'dhEmi', now('America/Sao_Paulo')->format('Y-m-d\TH:i:sP'));
        $this->el($doc, $inf, 'verAplic', config('nfse.versao_app'));
        $this->el($doc, $inf, 'serie', $nota->serie_dps);
        $this->el($doc, $inf, 'nDPS', (string) $nota->numero_dps);
        $this->el($doc, $inf, 'dCompet', $nota->data_competencia->format('Y-m-d'));
        $this->el($doc, $inf, 'tpEmit', '1');                // 1 = emitida pelo prestador
        $this->el($doc, $inf, 'finNFSe', '0');               // 0 = NFS-e regular (obrigatório no leiaute 20261020)
        $this->el($doc, $inf, 'cLocEmi', $p['codigo_municipio']);

        // Prestador
        $prest = $this->el($doc, $inf, 'prest');
        $this->el($doc, $prest, 'CNPJ', $p['cnpj']);
        // E0116/E0120: IM só vai se o prestador tiver cadastro complementar no CNC do município.
        // Sem cadastro no CNC, enviar IM causa rejeição — deixe a IM do emitente vazia.
        // xNome do prestador NÃO é enviado quando tpEmit = 1 (E0121).
        if ($p['inscricao_municipal']) {
            $this->el($doc, $prest, 'IM', $p['inscricao_municipal']);
        }
        $reg = $this->el($doc, $prest, 'regTrib');
        $this->el($doc, $reg, 'opSimpNac', (string) $p['op_simples_nacional']);
        // E0166/E0162: regApTribSN é obrigatório para ME/EPP (3) e proibido para os demais
        if ((int) $p['op_simples_nacional'] === 3) {
            $this->el($doc, $reg, 'regApTribSN', (string) $p['regime_apuracao_sn']);
        }
        $this->el($doc, $reg, 'regEspTrib', (string) $p['regime_especial']);

        // Tomador: "sem destinatário informado" segue sem o grupo toma (opcional no leiaute)
        if (! $nota->sem_tomador) {
            $toma = $this->el($doc, $inf, 'toma');
            $tag = strlen($nota->tomador_documento) === 11 ? 'CPF' : 'CNPJ';
            $this->el($doc, $toma, $tag, $nota->tomador_documento);
            $this->el($doc, $toma, 'xNome', $nota->tomador_nome);
            $this->enderecoTomador($doc, $toma, $nota);
            if ($nota->tomador_email) {
                $this->el($doc, $toma, 'email', $nota->tomador_email);   // o leiaute aceita um e-mail só: o principal
            }
        }

        // Serviço
        $serv = $this->el($doc, $inf, 'serv');
        $loc = $this->el($doc, $serv, 'locPrest');
        $this->el($doc, $loc, 'cLocPrestacao', $ajustes['cod_municipio_prestacao'] ?? $p['codigo_municipio']);
        $cServ = $this->el($doc, $serv, 'cServ');
        $this->el($doc, $cServ, 'cTribNac', $nota->codigo_servico);
        // cTribMun (3 dígitos): complementar do município, proibido para MEI (E0313)
        if (filled($p['codigo_tributacao_municipal'] ?? null) && (int) $p['op_simples_nacional'] !== 2) {
            $this->el($doc, $cServ, 'cTribMun', str_pad($p['codigo_tributacao_municipal'], 3, '0', STR_PAD_LEFT));
        }
        if ($nota->codigo_nbs) {
            $this->el($doc, $cServ, 'cNBS', $nota->codigo_nbs);
        }
        // Leiaute 20261020: xDescServ vem por último em cServ (após cNBS e cIntContrib)
        $this->el($doc, $cServ, 'xDescServ', $nota->descricao);

        // Valores e tributação
        $valores = $this->el($doc, $inf, 'valores');
        $vsp = $this->el($doc, $valores, 'vServPrest');
        $this->el($doc, $vsp, 'vServ', number_format((float) $nota->valor, 2, '.', ''));

        // Dedução/redução da base de cálculo do ISSQN (só emissão manual com ajuste): percentual OU valor
        if (($ajustes['deducao']['tipo'] ?? 'none') !== 'none') {
            $aj = $this->el($doc, $valores, 'vAjusteBC');
            $this->el($doc, $aj, $ajustes['deducao']['tipo'] === 'percentual' ? 'pAjusteBCISSQN' : 'vAjusteBCISSQN', number_format((float) $ajustes['deducao']['valor'], 2, '.', ''));
        }

        $trib = $this->el($doc, $valores, 'trib');
        $tribMun = $this->el($doc, $trib, 'tribMun');
        $tribIssqn = (int) ($ajustes['trib_issqn'] ?? 1);   // 1 tributável (padrão), 2 imunidade, 4 não incidência
        $this->el($doc, $tribMun, 'tribISSQN', (string) $tribIssqn);
        if ($tribIssqn === 2) {
            $this->el($doc, $tribMun, 'tpImunidade', (string) $ajustes['tp_imunidade']);
        }
        $retido = (int) ($ajustes['retencao_issqn'] ?? 1) === 2;   // 2 = retido pelo tomador (só emissão manual com ajuste)
        $this->el($doc, $tribMun, 'tpRetISSQN', $retido ? '2' : '1');
        // pAliq NÃO é enviado: com município conveniado ativo, o Anexo I proíbe informar alíquota
        // (E0617 não optante, E0625 ME/EPP), com ou sem retenção.

        $this->tributacaoFederal($doc, $trib, $p, $ajustes, (float) $nota->valor);
        $this->totaisAproximados($doc, $trib, $p, $ajustes, (float) $nota->valor);

        // IBSCBS: opcional no XSD 1.01. Se informado, exige cNBS (E0322).
        // Incluir quando o município/cliente exigir (fase 3).

        return $doc->saveXML($doc->documentElement);
    }

    /**
     * Tributação federal (só não optante; MEI não pode, E0676, e o optante do Simples segue sem o grupo).
     * Vale o padrão da empresa, como no Emissor Nacional: situação do PIS/COFINS (padrão "00 - Nenhum") e tipo de retenção
     * (padrão "0 - não retidos"); IRRF, contribuição social e previdenciária retidas ficam sem preenchimento.
     * Com situação diferente de 00 a base é o valor do serviço e as alíquotas vêm da empresa.
     * Na nota completa personalizada, a nota pode trazer o que mudar (situação, base, alíquotas, retenção e valores retidos).
     */
    private function tributacaoFederal(DOMDocument $doc, DOMElement $trib, array $p, array $ajustes = [], float $valorServico = 0.0): void
    {
        if ((int) $p['op_simples_nacional'] !== 1) {
            return;
        }

        $dec = fn ($v) => number_format((float) $v, 2, '.', '');
        $cst = (string) ($p['pis_cofins_cst'] ?? '00');
        $pc = $ajustes['pis_cofins'] ?? ['cst' => $cst, 'tp_ret' => (int) ($p['tp_ret_pis_cofins'] ?? 0)];
        if (! isset($ajustes['pis_cofins']) && $cst !== '00') {
            if ($p['aliquota_pis'] === null || $p['aliquota_cofins'] === null) {
                throw new \RuntimeException('Alíquotas de PIS e COFINS não configuradas no emitente (situação tributária diferente de 00).');
            }
            $pc += ['bc' => $valorServico, 'aliq_pis' => (float) $p['aliquota_pis'], 'aliq_cofins' => (float) $p['aliquota_cofins']];
        }

        $fed = $this->el($doc, $trib, 'tribFed');
        $pis = $this->el($doc, $fed, 'piscofins');
        $this->el($doc, $pis, 'CST', (string) $pc['cst']);
        if (isset($pc['bc'])) {   // XSD: CST, base, alíquotas, valores e, por último, o tipo de retenção
            $this->el($doc, $pis, 'vBCPisCofins', $dec($pc['bc']));
            $this->el($doc, $pis, 'pAliqPis', $dec($pc['aliq_pis']));
            $this->el($doc, $pis, 'pAliqCofins', $dec($pc['aliq_cofins']));
            $this->el($doc, $pis, 'vPis', $dec(round((float) $pc['bc'] * (float) $pc['aliq_pis'] / 100, 2)));        // E0694: valor = base × alíquota
            $this->el($doc, $pis, 'vCofins', $dec(round((float) $pc['bc'] * (float) $pc['aliq_cofins'] / 100, 2)));  // E0696
        }
        $this->el($doc, $pis, 'tpRetPisCofins', (string) $pc['tp_ret']);

        foreach (['ret_cp' => 'vRetCP', 'ret_irrf' => 'vRetIRRF', 'ret_csll' => 'vRetCSLL'] as $chave => $tag) {
            if (isset($ajustes[$chave])) {
                $this->el($doc, $fed, $tag, $dec($ajustes[$chave]));
            }
        }
    }

    /**
     * Total aproximado de tributos, na forma escolhida (empresa ou nota). O que a SEFIN aceita muda com o regime:
     * - MEI (2):          indTotTrib = 0, ou percentuais/valores  (E0710 proíbe pTotTribSN)
     * - ME/EPP (3):       pTotTribSN, percentuais ou valores      (E0712 proíbe indTotTrib)
     * - Não optante (1):  percentuais ou valores                  (E0713 proíbe indTotTrib e pTotTribSN)
     * Valores em R$ ("preencher em cada NFS-e"): os da nota, ou o percentual da empresa aplicado ao valor do serviço.
     */
    private function totaisAproximados(DOMDocument $doc, DOMElement $trib, array $p, array $ajustes = [], float $valorServico = 0.0): void
    {
        $totTrib = $this->el($doc, $trib, 'totTrib');
        $fmt = function ($v) {
            if ($v === null || $v === '') {
                throw new \RuntimeException('Percentual de tributos aproximados não configurado no emitente (alíquota do Simples ou tributos aproximados).');
            }

            return number_format((float) $v, 2, '.', '');
        };

        $modo = $ajustes['modo_tributos_aprox'] ?? $p['modo_tributos_aprox'] ?? match ((int) $p['op_simples_nacional']) {
            2 => 'nenhum', 3 => 'simples', default => 'percentual',
        };

        switch ($modo) {
            case 'nenhum':
                $this->el($doc, $totTrib, 'indTotTrib', '0');
                break;
            case 'simples':
                $this->el($doc, $totTrib, 'pTotTribSN', $fmt($p['aliquota_simples']));
                break;
            case 'monetario':
                $v = $ajustes['valores_tributos'] ?? array_map(
                    fn ($pct) => $pct === null || $pct === '' ? $fmt(null) : round($valorServico * (float) $pct / 100, 2),
                    $p['tributos_aprox'] + ['federal' => null, 'estadual' => null, 'municipal' => null],
                );
                $mon = $this->el($doc, $totTrib, 'vTotTrib');
                $this->el($doc, $mon, 'vTotTribFed', $fmt($v['federal']));
                $this->el($doc, $mon, 'vTotTribEst', $fmt($v['estadual']));
                $this->el($doc, $mon, 'vTotTribMun', $fmt($v['municipal']));
                break;
            default:   // percentual
                $pct = $this->el($doc, $totTrib, 'pTotTrib');
                $this->el($doc, $pct, 'pTotTribFed', $fmt($p['tributos_aprox']['federal']));
                $this->el($doc, $pct, 'pTotTribEst', $fmt($p['tributos_aprox']['estadual']));
                $this->el($doc, $pct, 'pTotTribMun', $fmt($p['tributos_aprox']['municipal']));
        }
    }

    /**
     * Tributação municipal padrão da empresa, no mesmo formato dos ajustes da nota (a nota sobrepõe o que mudar).
     * Só entra o que foge do padrão do sistema, para a DPS comum continuar igual.
     */
    private function padroesDaEmpresa(array $p): array
    {
        $a = [];
        if ((int) ($p['trib_issqn'] ?? 1) !== 1) {
            $a['trib_issqn'] = (int) $p['trib_issqn'];
            if (! empty($p['tp_imunidade'])) {
                $a['tp_imunidade'] = (int) $p['tp_imunidade'];
            }
        }
        if ((int) ($p['retencao_issqn'] ?? 1) === 2) {
            $a['retencao_issqn'] = 2;
        }
        if (! empty($p['deducao_tipo']) && $p['deducao_valor'] !== null) {
            $a['deducao'] = ['tipo' => $p['deducao_tipo'], 'valor' => (float) $p['deducao_valor']];
        }
        if (! empty($p['codigo_municipio_prestacao'])) {
            $a['cod_municipio_prestacao'] = $p['codigo_municipio_prestacao'];
        }

        return $a;
    }

    /** Endereço do cadastro do cliente, só quando está completo (município IBGE, CEP, logradouro, número e bairro). */
    private function enderecoTomador(DOMDocument $doc, DOMElement $toma, NotaFiscal $nota): void
    {
        $t = $nota->tomador;
        if (! $t || ! $t->enderecoCompleto()) {
            return;
        }

        $end = $this->el($doc, $toma, 'end');
        $nac = $this->el($doc, $end, 'endNac');
        $this->el($doc, $nac, 'cMun', $t->codigo_municipio);
        $this->el($doc, $nac, 'CEP', $t->cep);
        $this->el($doc, $end, 'xLgr', $t->logradouro);
        $this->el($doc, $end, 'nro', $t->numero);
        if (filled($t->complemento)) {
            $this->el($doc, $end, 'xCpl', $t->complemento);
        }
        $this->el($doc, $end, 'xBairro', $t->bairro);
    }

    private function el(DOMDocument $doc, DOMElement $pai, string $nome, ?string $valor = null): DOMElement
    {
        $el = $doc->createElementNS(self::NS, $nome);
        if ($valor !== null) {
            $el->appendChild($doc->createTextNode($valor)); // createTextNode já escapa &, <, >
        }
        $pai->appendChild($el);

        return $el;
    }
}
