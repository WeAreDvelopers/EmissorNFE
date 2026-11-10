<?php

namespace App\Services;

use App\Support\MunicipiosIbge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Consulta de CEP para preencher o endereço do cliente.
 * Tenta o ViaCEP e, se estiver fora do ar, a BrasilAPI. Só o CEP sai do sistema (nenhum dado do cliente).
 * Acertos ficam em cache por 30 dias; CEP não encontrado ou serviços fora do ar não são guardados.
 */
class ConsultaCep
{
    /** @return array{cep:string,logradouro:?string,bairro:?string,municipio:?string,uf:?string,codigo_municipio:?string}|null */
    public function buscar(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            return null;
        }

        if ($guardado = Cache::get("cep:{$cep}")) {
            return $guardado;
        }

        $dados = $this->viaCep($cep) ?? $this->brasilApi($cep);
        if ($dados) {
            Cache::put("cep:{$cep}", $dados, now()->addDays(30));
        }

        return $dados;
    }

    private function viaCep(string $cep): ?array
    {
        try {
            $r = Http::timeout(4)->acceptJson()->get("https://viacep.com.br/ws/{$cep}/json/");
            $d = $r->json();
            if (! $r->successful() || ! is_array($d) || ! empty($d['erro'])) {
                return null;
            }

            return $this->formatar($cep, $d['logradouro'] ?? null, $d['bairro'] ?? null, $d['localidade'] ?? null, $d['uf'] ?? null, $d['ibge'] ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    private function brasilApi(string $cep): ?array
    {
        try {
            $r = Http::timeout(4)->acceptJson()->get("https://brasilapi.com.br/api/cep/v1/{$cep}");
            $d = $r->json();
            if (! $r->successful() || ! is_array($d) || empty($d['city'])) {
                return null;
            }

            return $this->formatar($cep, $d['street'] ?? null, $d['neighborhood'] ?? null, $d['city'] ?? null, $d['state'] ?? null, null);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatar(string $cep, ?string $logradouro, ?string $bairro, ?string $municipio, ?string $uf, ?string $ibge): array
    {
        $limpa = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;
        $municipio = $limpa($municipio);
        $uf = $limpa($uf) ? strtoupper($limpa($uf)) : null;

        return [
            'cep'              => $cep,
            'logradouro'       => $limpa($logradouro),
            'bairro'           => $limpa($bairro),
            'municipio'        => $municipio,
            'uf'               => $uf,
            'codigo_municipio' => $limpa($ibge) ?: (($municipio && $uf) ? MunicipiosIbge::codigo($municipio, $uf) : null),
        ];
    }
}
