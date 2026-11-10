<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Código IBGE (7 dígitos) a partir de município + UF, pela API pública do IBGE (cache de 30 dias por UF). */
class MunicipiosIbge
{
    public static function codigo(?string $municipio, ?string $uf): ?string
    {
        $uf = strtoupper(trim((string) $uf));
        if (blank($municipio) || strlen($uf) !== 2) {
            return null;
        }

        $mapa = self::porUf($uf);

        return $mapa[self::chave($municipio)] ?? null;
    }

    /** "Cidade/UF" a partir do código IBGE (cache de 30 dias); null se a consulta falhar. */
    public static function rotulo(?string $codigo): ?string
    {
        if (! preg_match('/^\d{7}$/', (string) $codigo)) {
            return null;
        }

        if ($guardado = Cache::get("ibge:rotulo:{$codigo}")) {
            return $guardado;
        }

        try {
            $r = Http::timeout(3)->get("https://servicodados.ibge.gov.br/api/v1/localidades/municipios/{$codigo}");
            $m = $r->successful() ? $r->json() : null;
            $uf = $m['microrregiao']['mesorregiao']['UF']['sigla'] ?? $m['regiao-imediata']['regiao-intermediaria']['UF']['sigla'] ?? null;
            if (! isset($m['nome'], $uf)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        Cache::put("ibge:rotulo:{$codigo}", $rotulo = "{$m['nome']}/{$uf}", now()->addDays(30));

        return $rotulo;
    }

    /** @return array<string,string> nome normalizado => código */
    private static function porUf(string $uf): array
    {
        $mapa = Cache::get("ibge:municipios:{$uf}");
        if (is_array($mapa)) {
            return $mapa;
        }

        try {
            $resposta = Http::timeout(10)->retry(2, 400, throw: false)->get("https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$uf}/municipios");
            if (! $resposta->successful()) {
                return [];
            }
            $mapa = collect($resposta->json())->mapWithKeys(fn ($m) => [self::chave($m['nome']) => (string) $m['id']])->all();
        } catch (\Throwable) {
            return [];                                  // sem rede: não guarda no cache, tenta de novo na próxima
        }

        Cache::put("ibge:municipios:{$uf}", $mapa, now()->addDays(30));

        return $mapa;
    }

    private static function chave(string $nome): string
    {
        return Str::of($nome)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
