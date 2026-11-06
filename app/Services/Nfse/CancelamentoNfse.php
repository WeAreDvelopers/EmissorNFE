<?php

namespace App\Services\Nfse;

use App\Enums\StatusNota;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Cancelamento de NFS-e: pedido de registro de evento 101101, assinado pelo prestador e enviado à SEFIN.
 * Só cancela nota EMITIDA. O crédito não volta (a nota foi autorizada e cobrada).
 */
class CancelamentoNfse
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';
    public const MOTIVOS = [1 => 'Erro na emissão', 2 => 'Serviço não prestado', 9 => 'Outros'];

    public function __construct(private ValidadorXsd $xsd, private AssinadorXml $assinador) {}

    /** Id do pedido: "PRE" + chave de acesso (50) + tipo do evento (101101) = 59 caracteres. */
    public function idPedido(string $chaveAcesso): string
    {
        return 'PRE'.$chaveAcesso.'101101';
    }

    public function montarPedido(NotaFiscal $nota, int $codigoMotivo, string $texto): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;

        $raiz = $doc->createElementNS(self::NS, 'pedRegEvento');
        $raiz->setAttribute('versao', config('nfse.versao_dps'));
        $doc->appendChild($raiz);

        $inf = $this->el($doc, $raiz, 'infPedReg');
        $inf->setAttribute('Id', $this->idPedido($nota->chave_acesso));

        $this->el($doc, $inf, 'tpAmb', (string) $nota->emitente->ambiente);
        $this->el($doc, $inf, 'verAplic', config('nfse.versao_app'));
        $this->el($doc, $inf, 'dhEvento', now('America/Sao_Paulo')->format('Y-m-d\TH:i:sP'));
        $this->el($doc, $inf, 'CNPJAutor', $nota->emitente->cnpj);
        $this->el($doc, $inf, 'chNFSe', $nota->chave_acesso);

        $e = $this->el($doc, $inf, 'e101101');
        $this->el($doc, $e, 'xDesc', 'Cancelamento de NFS-e');
        $this->el($doc, $e, 'cMotivo', (string) $codigoMotivo);
        $this->el($doc, $e, 'xMotivo', $texto);

        return $doc->saveXML($doc->documentElement);
    }

    /** Valida os dados, monta, confere no XSD, assina, envia e marca a nota como cancelada. */
    public function cancelar(NotaFiscal $nota, int $codigoMotivo, string $texto, ?SefinNacionalClient $cliente = null, ?Certificado $certificado = null): void
    {
        $texto = trim($texto);

        if ($nota->status !== StatusNota::Emitida || ! $nota->chave_acesso) {
            throw new InvalidArgumentException('Só dá para cancelar uma NFS-e que está emitida.');
        }
        if (! isset(self::MOTIVOS[$codigoMotivo])) {
            throw new InvalidArgumentException('Escolha o motivo do cancelamento.');
        }
        if (mb_strlen($texto) < 15 || mb_strlen($texto) > 255) {
            throw new InvalidArgumentException('Explique o motivo com 15 a 255 caracteres.');
        }

        $emitente = Emitente::semIsolamento()->findOrFail($nota->emitente_id);
        $nota->setRelation('emitente', $emitente);

        $xml = $this->montarPedido($nota, $codigoMotivo, $texto);
        if ($erros = $this->xsd->erros($xml, config('nfse.xsd_evento'))) {
            throw new InvalidArgumentException('O pedido de cancelamento não passou na validação: '.implode('; ', $erros));
        }

        $assinado = $this->assinador->assinar($xml, $certificado ?? Certificado::doEmitente($emitente), 'infPedReg');
        $retorno = ($cliente ?? SefinNacionalClient::para($emitente))->cancelar($nota->chave_acesso, $assinado);

        $nota->forceFill([
            'status'              => StatusNota::Cancelada,
            'cancelada_em'        => now(),
            'cancelamento_codigo' => (string) $codigoMotivo,
            'cancelamento_motivo' => $texto,
            'xml_cancelamento'    => $retorno['xml_evento'] ?? $assinado,
        ])->save();
    }

    private function el(DOMDocument $doc, DOMElement $pai, string $nome, ?string $valor = null): DOMElement
    {
        $el = $doc->createElementNS(self::NS, $nome);
        if ($valor !== null) {
            $el->appendChild($doc->createTextNode($valor));
        }
        $pai->appendChild($el);

        return $el;
    }
}
