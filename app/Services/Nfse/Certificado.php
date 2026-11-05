<?php

namespace App\Services\Nfse;

use App\Models\Emitente;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Certificado A1 de UM emitente, aberto em memória.
 * O .pfx fica no disco criptografado com a APP_KEY; a senha fica criptografada no banco.
 */
class Certificado
{
    private array $dados = [];
    private array $info = [];

    public function __construct(string $pfxBinario, string $senha)
    {
        if (! openssl_pkcs12_read($pfxBinario, $this->dados, $senha)) {
            throw new RuntimeException('Não foi possível abrir o certificado (senha errada ou .pfx com algoritmo legado — veja o README).');
        }

        $this->info = openssl_x509_parse($this->dados['cert']) ?: [];
    }

    public static function doEmitente(Emitente $emitente): self
    {
        if (! $emitente->certificado_arquivo) {
            throw new RuntimeException("Emitente {$emitente->cnpj} sem certificado cadastrado.");
        }

        $binario = base64_decode(Crypt::decryptString(Storage::disk('local')->get($emitente->certificado_arquivo)));

        return new self($binario, (string) $emitente->certificado_senha);
    }

    /** Usado no upload: valida e guarda criptografado. Devolve o caminho no disco. */
    public static function guardar(Emitente $emitente, string $pfxBinario): string
    {
        $caminho = "certificados/emitente-{$emitente->id}.pfx.enc";
        Storage::disk('local')->put($caminho, Crypt::encryptString(base64_encode($pfxBinario)));

        return $caminho;
    }

    public function chavePrivada(): string
    {
        return $this->dados['pkey'];
    }

    public function certificadoPublico(): string
    {
        return $this->dados['cert'];
    }

    public function validoAte(): ?Carbon
    {
        return isset($this->info['validTo_time_t']) ? Carbon::createFromTimestamp($this->info['validTo_time_t']) : null;
    }

    /**
     * CNPJ do titular. Certificados ICP-Brasil e-CNPJ costumam trazer "RAZÃO:CNPJ" no CN;
     * a fonte oficial é o OID 2.16.76.1.3.3 (E1209) — esta leitura é uma checagem de conveniência.
     */
    public function cnpjProvavel(): ?string
    {
        $cn = (string) ($this->info['subject']['CN'] ?? '');

        return preg_match('/(\d{14})/', $cn, $m) ? $m[1] : null;
    }

    /** Razão social do titular: o CN do e-CNPJ costuma vir como "RAZÃO SOCIAL:CNPJ". Só conveniência para preencher a tela. */
    public function razaoSocialProvavel(): ?string
    {
        $cn = trim((string) ($this->info['subject']['CN'] ?? ''));
        $nome = trim(preg_replace('/:?\s*\d{14}\s*$/', '', $cn), " :");

        return $nome !== '' ? $nome : null;
    }

    /** Arquivos PEM temporários para o mTLS do Guzzle (um par por certificado). */
    public function arquivosPem(): array
    {
        $dir = storage_path('app/private/certificados/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $id = hash('sha256', $this->dados['cert']);
        $cert = "{$dir}/{$id}-cert.pem";
        $key = "{$dir}/{$id}-key.pem";

        if (! file_exists($cert) || ! file_exists($key)) {
            file_put_contents($cert, $this->certificadoPublico());
            file_put_contents($key, $this->chavePrivada());
            chmod($cert, 0600);
            chmod($key, 0600);
        }

        return ['cert' => $cert, 'key' => $key];
    }
}
