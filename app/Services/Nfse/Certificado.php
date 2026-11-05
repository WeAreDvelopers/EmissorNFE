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
    private string $pfx;

    public function __construct(string $pfxBinario, string $senha)
    {
        $this->pfx = $pfxBinario;

        if (! openssl_pkcs12_read($pfxBinario, $this->dados, $senha)) {
            // OpenSSL 3 não abre .pfx com criptografia legada (RC2-40, comum em A1 antigos): converte uma vez.
            $moderno = self::converterLegado($pfxBinario, $senha);

            if ($moderno === null || ! openssl_pkcs12_read($moderno, $this->dados, $senha)) {
                throw new RuntimeException('Não foi possível abrir o certificado (senha errada ou arquivo inválido).');
            }
            $this->pfx = $moderno;
        }

        $this->info = openssl_x509_parse($this->dados['cert']) ?: [];
    }

    /** .pfx já no formato que o PHP lê (o convertido, se o original era legado). Guardar este. */
    public function pfx(): string
    {
        return $this->pfx;
    }

    /** Reempacota um .pfx legado com o provider "legacy" do OpenSSL CLI. Senha só por variável de ambiente. */
    private static function converterLegado(string $pfxBinario, string $senha): ?string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pfx-'.bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0700)) {
            return null;
        }

        $env = ['NFSE_PFX_SENHA' => $senha] + (getenv() ?: []);
        $roda = function (array $cmd) use ($env): bool {
            $p = new \Symfony\Component\Process\Process($cmd, null, $env, null, 30);
            $p->run();

            return $p->isSuccessful();
        };

        try {
            file_put_contents("$dir/origem.pfx", $pfxBinario);
            // OpenSSL 3 precisa de -legacy; o 1.1 não conhece a flag e lê o formato antigo sozinho.
            $ok = false;
            foreach ([['-legacy'], []] as $extra) {
                $ok = $roda(['openssl', 'pkcs12', ...$extra, '-in', "$dir/origem.pfx", '-passin', 'env:NFSE_PFX_SENHA',
                    '-passout', 'env:NFSE_PFX_SENHA', '-out', "$dir/tudo.pem"]);
                if ($ok) {
                    break;
                }
            }
            // Algoritmos explícitos: o padrão do OpenSSL 1.1 continua sendo o legado.
            $ok = $ok && $roda(['openssl', 'pkcs12', '-export', '-certpbe', 'AES-256-CBC', '-keypbe', 'AES-256-CBC', '-macalg', 'sha256',
                    '-in', "$dir/tudo.pem", '-passin', 'env:NFSE_PFX_SENHA', '-passout', 'env:NFSE_PFX_SENHA', '-out', "$dir/novo.pfx"]);

            return $ok ? (file_get_contents("$dir/novo.pfx") ?: null) : null;
        } finally {
            foreach (glob("$dir/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
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
