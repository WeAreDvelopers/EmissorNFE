<?php

namespace App\Services\Nfse;

use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Assinatura XMLDSig "enveloped" da DPS sobre o elemento infDPS (atributo Id).
 *
 * Regras do Anexo I que pegam aqui:
 * - E1228: proibido prefixo de namespace → Signature SEM "ds:" (por isso o prefixo '')
 * - E1229: XML precisa declarar UTF-8 → saída com <?xml ... encoding="UTF-8"?>
 */
class AssinadorXml
{
    /** O certificado vem do emitente da nota (cada CNPJ tem o seu). */
    public function assinar(string $xml, Certificado $certificado, string $elementoAssinado = 'infDPS'): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($xml);
        $doc->encoding = 'UTF-8'; // loadXML de uma string sem declaração zera o encoding

        $inf = $doc->getElementsByTagName($elementoAssinado)->item(0);   // infDPS (DPS) ou infPedReg (pedido de evento)

        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::C14N);
        $dsig->addReference(
            $inf,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::C14N],
            ['id_name' => 'Id', 'overwrite' => false]
        );

        $chave = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $chave->loadKey($certificado->chavePrivada());

        $dsig->sign($chave);
        $dsig->add509Cert($certificado->certificadoPublico());
        $dsig->appendSignature($doc->documentElement); // <Signature> como irmã de infDPS

        return $doc->saveXML(); // inclui a declaração UTF-8 (E1229)
    }
}
