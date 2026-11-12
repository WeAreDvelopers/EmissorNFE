<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Município x código IBGE (7 dígitos), pela tabela oficial do IBGE guardada no projeto (database/data/municipios_ibge.json).
 *
 * É local de propósito: a API pública do IBGE ficou inacessível a partir do servidor e cada consulta travava a tela por segundos.
 * Os códigos dos municípios mudam raramente; para atualizar a tabela, baixe de
 * https://servicodados.ibge.gov.br/api/v1/localidades/municipios e regrave o arquivo como [[codigo, nome, uf], ...].
 */
class MunicipiosIbge
{
    /** @var array<int,array{0:string,1:string,2:string}>|null */
    private static ?array $tabela = null;

    /** @var array<string,array<string,string>>|null uf => (nome normalizado => código) */
    private static ?array $porUf = null;

    public static function codigo(?string $municipio, ?string $uf): ?string
    {
        $uf = strtoupper(trim((string) $uf));
        if (blank($municipio) || strlen($uf) !== 2) {
            return null;
        }

        return self::mapa()[$uf][self::chave($municipio)] ?? null;
    }

    /** "Cidade/UF" a partir do código IBGE; null se o código não existir. */
    public static function rotulo(?string $codigo): ?string
    {
        if (! preg_match('/^\d{7}$/', (string) $codigo)) {
            return null;
        }

        foreach (self::tabela() as [$cod, $nome, $uf]) {
            if ($cod === $codigo) {
                return "{$nome}/{$uf}";
            }
        }

        return null;
    }

    /** @return array<string,array<string,string>> */
    private static function mapa(): array
    {
        if (self::$porUf === null) {
            self::$porUf = [];
            foreach (self::tabela() as [$cod, $nome, $uf]) {
                self::$porUf[$uf][self::chave($nome)] = $cod;
            }
        }

        return self::$porUf;
    }

    private static function tabela(): array
    {
        return self::$tabela ??= (json_decode((string) @file_get_contents(database_path('data/municipios_ibge.json')), true) ?: []);
    }

    private static function chave(string $nome): string
    {
        return Str::of($nome)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
