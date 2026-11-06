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
        $p = $nota->emitente->paraDps();   // cada CNPJ da conta tem seu regime, município e série

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
        $this->el($doc, $loc, 'cLocPrestacao', $p['codigo_municipio']);
        $cServ = $this->el($doc, $serv, 'cServ');
        $this->el($doc, $cServ, 'cTribNac', $nota->codigo_servico);
        if ($nota->codigo_nbs) {
            $this->el($doc, $cServ, 'cNBS', $nota->codigo_nbs);
        }
        // Leiaute 20261020: xDescServ vem por último em cServ (após cNBS e cIntContrib)
        $this->el($doc, $cServ, 'xDescServ', $nota->descricao);

        // Valores e tributação
        $valores = $this->el($doc, $inf, 'valores');
        $vsp = $this->el($doc, $valores, 'vServPrest');
        $this->el($doc, $vsp, 'vServ', number_format((float) $nota->valor, 2, '.', ''));

        $trib = $this->el($doc, $valores, 'trib');
        $tribMun = $this->el($doc, $trib, 'tribMun');
        $this->el($doc, $tribMun, 'tribISSQN', '1');   // 1 = operação tributável
        $this->el($doc, $tribMun, 'tpRetISSQN', '1');  // 1 = não retido
        // pAliq NÃO é enviado: com município conveniado ativo e sem retenção,
        // o Anexo I proíbe informar alíquota (E0617 não optante, E0625 ME/EPP).

        $this->totaisAproximados($doc, $trib, $p);

        // IBSCBS: opcional no XSD 1.01. Se informado, exige cNBS (E0322).
        // Incluir quando o município/cliente exigir (fase 3).

        return $doc->saveXML($doc->documentElement);
    }

    /**
     * Total aproximado de tributos: o campo permitido muda com o regime (Anexo I):
     * - MEI (2):          indTotTrib = 0                      (E0710 proíbe pTotTribSN)
     * - ME/EPP (3):       pTotTribSN = alíquota do Simples    (E0712 proíbe indTotTrib)
     * - Não optante (1):  pTotTrib fed/est/mun                (E0713 proíbe indTotTrib e pTotTribSN)
     */
    private function totaisAproximados(DOMDocument $doc, DOMElement $trib, array $p): void
    {
        $totTrib = $this->el($doc, $trib, 'totTrib');
        $fmt = function ($v) {
            if ($v === null || $v === '') {
                throw new \RuntimeException('Percentual de tributos aproximados não configurado no emitente (alíquota do Simples ou tributos aproximados).');
            }

            return number_format((float) $v, 2, '.', '');
        };

        match ((int) $p['op_simples_nacional']) {
            2 => $this->el($doc, $totTrib, 'indTotTrib', '0'),
            3 => $this->el($doc, $totTrib, 'pTotTribSN', $fmt($p['aliquota_simples'])),
            default => (function () use ($doc, $totTrib, $p, $fmt) {
                $pct = $this->el($doc, $totTrib, 'pTotTrib');
                $this->el($doc, $pct, 'pTotTribFed', $fmt($p['tributos_aprox']['federal']));
                $this->el($doc, $pct, 'pTotTribEst', $fmt($p['tributos_aprox']['estadual']));
                $this->el($doc, $pct, 'pTotTribMun', $fmt($p['tributos_aprox']['municipal']));
            })(),
        };
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
