<?php

namespace App\Services\Nfse;

use DOMDocument;
use RuntimeException;

/**
 * Valida a DPS contra o XSD oficial ANTES de enviar.
 * Um erro de esquema aqui vira E1235 na SEFIN; pegando localmente,
 * a nota fica "Rejeitada" com a mensagem exata, sem gastar chamada à API.
 */
class ValidadorXsd
{
    /** @return string[] lista de erros (vazia = válido) */
    public function erros(string $xml, ?string $xsd = null): array
    {
        // DPS de produção segue o leiaute da produção (o XML traz o ambiente em tpAmb)
        $producaoAntiga = $xsd === null && str_contains($xml, '<tpAmb>1</tpAmb>') && ! DpsBuilder::leiauteNovo(1);
        $xsd ??= config($producaoAntiga ? 'nfse.xsd_dps_producao' : 'nfse.xsd_dps');

        if (! is_readable($xsd)) {
            throw new RuntimeException("XSD não encontrado em {$xsd}");
        }

        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $doc->schemaValidate($xsd);

        $erros = array_map(
            fn ($e) => trim(preg_replace('/\{http[^}]+\}/', '', $e->message)),
            libxml_get_errors()
        );

        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return array_values(array_unique($erros));
    }
}
