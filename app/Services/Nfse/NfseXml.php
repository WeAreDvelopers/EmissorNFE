<?php

namespace App\Services\Nfse;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Lê o XML da NFS-e autorizada (devolvido pela SEFIN) e entrega os campos já prontos para o DANFSE e o e-mail.
 * Só lê: não valida assinatura (a SEFIN já autorizou) nem altera o documento.
 */
class NfseXml
{
    private const NS = 'http://www.sped.fazenda.gov.br/nfse';

    private DOMXPath $xp;

    private function __construct(DOMDocument $doc)
    {
        $this->xp = new DOMXPath($doc);
        $this->xp->registerNamespace('n', self::NS);
    }

    /** @return array<string, mixed> */
    public static function ler(string $xml): array
    {
        $doc = new DOMDocument();
        if (! @$doc->loadXML($xml)) {
            throw new RuntimeException('XML da NFS-e inválido.');
        }

        return (new self($doc))->extrair();
    }

    private function v(string $caminho): ?string
    {
        $no = $this->xp->query('/n:NFSe/n:infNFSe/'.$this->prefixar($caminho))->item(0);
        $texto = $no ? trim($no->textContent) : '';

        return $texto === '' ? null : $texto;
    }

    private function prefixar(string $caminho): string
    {
        return implode('/', array_map(fn ($p) => 'n:'.$p, explode('/', $caminho)));
    }

    private function extrair(): array
    {
        $id = $this->xp->query('/n:NFSe/n:infNFSe/@Id')->item(0)?->nodeValue;
        $dps = 'DPS/infDPS/';

        $docPrestador = $this->v('emit/CNPJ') ?? $this->v('emit/CPF');
        $docTomador = $this->v($dps.'toma/CNPJ') ?? $this->v($dps.'toma/CPF');
        $cep = $this->v('emit/enderNac/CEP');

        return [
            'chave'           => $id ? preg_replace('/^NFS/', '', $id) : null,
            'numero'          => $this->v('nNFSe'),
            'processada_em'   => $this->v('dhProc'),
            'situacao_codigo' => $this->v('cStat'),
            'ambiente'        => (int) ($this->v('ambGer') ?? $this->v($dps.'tpAmb') ?? 2),
            'municipio_emissor'   => $this->v('xLocEmi'),
            'local_prestacao'     => $this->v('xLocPrestacao'),
            'municipio_incidencia' => $this->v('xLocIncid'),
            'trib_nac_descricao'  => $this->v('xTribNac'),
            'verificacao'         => $this->v('verAplic'),

            'dps' => [
                'serie'       => $this->v($dps.'serie'),
                'numero'      => $this->v($dps.'nDPS'),
                'emitida_em'  => $this->v($dps.'dhEmi'),
                'competencia' => $this->v($dps.'dCompet'),
                'emitente'    => $this->v($dps.'tpEmit'),
            ],

            'prestador' => [
                'documento' => $docPrestador,
                'nome'      => $this->v('emit/xNome'),
                'fantasia'  => $this->v('emit/xFant'),
                'telefone'  => $this->v('emit/fone'),
                'email'     => $this->v('emit/email'),
                'logradouro' => $this->v('emit/enderNac/xLgr'),
                'numero'    => $this->v('emit/enderNac/nro'),
                'complemento' => $this->v('emit/enderNac/xCpl'),
                'bairro'    => $this->v('emit/enderNac/xBairro'),
                'municipio_ibge' => $this->v('emit/enderNac/cMun'),
                'uf'        => $this->v('emit/enderNac/UF'),
                'cep'       => $cep ? str_pad(preg_replace('/\D/', '', $cep), 8, '0', STR_PAD_LEFT) : null,
                'simples'   => $this->v($dps.'prest/regTrib/opSimpNac'),
                'apuracao_sn' => $this->v($dps.'prest/regTrib/regApTribSN'),
            ],

            'tomador' => [
                'documento' => $docTomador,
                'nome'      => $this->v($dps.'toma/xNome'),
                'email'     => $this->v($dps.'toma/email'),
            ],

            'servico' => [
                'codigo_nacional'   => $this->v($dps.'serv/cServ/cTribNac'),
                'codigo_municipal'  => $this->v($dps.'serv/cServ/cTribMun'),
                'nbs'               => $this->v($dps.'serv/cServ/cNBS'),
                'descricao'         => $this->v($dps.'serv/cServ/xDescServ'),
            ],

            'tributacao' => [
                'tipo'      => $this->v($dps.'valores/trib/tribMun/tribISSQN'),
                'retencao'  => $this->v($dps.'valores/trib/tribMun/tpRetISSQN'),
                'aliquota'  => $this->v('valores/pAliqAplic') ?? $this->v($dps.'valores/trib/tribMun/pAliq'),
                'base'      => $this->v('valores/vBC'),
                'issqn'     => $this->v('valores/vISSQN'),
            ],

            'valores' => [
                'servico'   => $this->v($dps.'valores/vServPrest/vServ'),
                'desconto'  => $this->v($dps.'valores/vDescIncond') ?? $this->v($dps.'valores/vDescCond'),
                'liquido'   => $this->v('valores/vLiq'),
            ],
        ];
    }
}
