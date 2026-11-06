<?php

namespace App\Services\Nfse;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente da API SEFIN Nacional (emissão de NFS-e a partir de DPS).
 * Autenticação: mTLS com o certificado A1 do prestador.
 *
 * Endpoints conforme o Swagger em /SefinNacional/docs — confirme nomes de campos
 * e códigos de retorno na versão vigente antes de ir para produção.
 */
class SefinNacionalClient
{
    public function __construct(private Certificado $certificado, private int $ambiente) {}

    /** Um cliente por emitente: certificado e ambiente (restrita/produção) são dele. */
    public static function para(\App\Models\Emitente $emitente): self
    {
        return new self(Certificado::doEmitente($emitente), (int) $emitente->ambiente);
    }

    /**
     * Envia a DPS assinada. Retorna chave de acesso e XML da NFS-e.
     *
     * @throws RejeicaoSefinException  quando a SEFIN recusa por regra de negócio (4xx)
     * @throws RuntimeException        em erro técnico (5xx, timeout) — o job tenta de novo
     */
    public function emitir(string $xmlDpsAssinado): array
    {
        $resposta = $this->http()->post('/nfse', [
            'dpsXmlGZipB64' => base64_encode(gzencode($xmlDpsAssinado)),
        ]);

        if ($resposta->successful()) {
            return $this->extrairNfse($resposta->json());
        }

        if ($resposta->clientError()) {
            Log::warning('SEFIN rejeitou a DPS', [
                'status' => $resposta->status(),
                'corpo'  => $resposta->body(),
            ]);

            // "erro" (singular) vem como objeto único; "erros" como lista.
            $bruto = $resposta->json('erros') ?? $resposta->json('erro') ?? [$resposta->body()];
            if (is_array($bruto) && array_is_list($bruto) === false) {
                $bruto = [$bruto];
            }

            // O "complemento" traz o motivo real (ex.: elemento que violou o esquema no E1235).
            $erros = collect($bruto)
                ->map(function ($e) {
                    if (! is_array($e)) {
                        return (string) $e;
                    }

                    $texto = trim(($e['codigo'] ?? $e['Codigo'] ?? '').' '.($e['descricao'] ?? $e['Descricao'] ?? ''));
                    $compl = $e['complemento'] ?? $e['Complemento'] ?? null;

                    return $compl ? "{$texto} — {$compl}" : ($texto ?: json_encode($e, JSON_UNESCAPED_UNICODE));
                })
                ->values()
                ->all();

            throw new RejeicaoSefinException($erros);
        }

        throw new RuntimeException("SEFIN indisponível (HTTP {$resposta->status()})");
    }

    /** Verifica se uma DPS já virou NFS-e (usado antes de reenviar após timeout). */
    public function consultarPorDps(string $idDps): ?array
    {
        $resposta = $this->http()->get("/dps/{$idDps}");

        if ($resposta->notFound()) {
            return null;
        }

        $resposta->throw();
        $chave = $resposta->json('chaveAcesso');
        if (! $chave) {
            return null;
        }

        // Autorizada e a resposta se perdeu: busca a NFS-e pela chave para guardar número e XML (e poder gerar o DANFSE)
        $nfse = $this->http()->get("/nfse/{$chave}");
        if ($nfse->successful() && $nfse->json('nfseXmlGZipB64')) {
            return $this->extrairNfse($nfse->json() + ['chaveAcesso' => $chave]);
        }

        return ['chave_acesso' => $chave];
    }

    private function extrairNfse(array $json): array
    {
        $xml = isset($json['nfseXmlGZipB64'])
            ? gzdecode(base64_decode($json['nfseXmlGZipB64']))
            : null;

        $numero = null;
        if ($xml && preg_match('#<nNFSe>(\d+)</nNFSe>#', $xml, $m)) {
            $numero = $m[1];
        }

        return [
            'chave_acesso' => $json['chaveAcesso'] ?? throw new RuntimeException('Resposta sem chaveAcesso'),
            'numero_nfse'  => $numero,
            'xml_nfse'     => $xml,
        ];
    }

    private function http(): PendingRequest
    {
        $pem = $this->certificado->arquivosPem();

        return Http::baseUrl(config('nfse.urls.'.$this->ambiente.'.sefin'))
            ->acceptJson()
            ->timeout(30)
            ->withOptions([
                'cert'    => $pem['cert'],
                'ssl_key' => $pem['key'],
            ]);
    }
}
