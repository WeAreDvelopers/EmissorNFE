<?php

namespace App\Support;

/** Nomenclatura Brasileira de Serviços (NBS 2.0, itens de 9 dígitos), usada no campo cNBS da DPS. Fonte: CSV oficial do MDIC. */
class ListaNbs
{
    private static ?array $lista = null;

    public static function todos(): array
    {
        return self::$lista ??= require resource_path('data/nbs.php');
    }

    public static function existe(string $codigo): bool
    {
        return isset(self::todos()[$codigo]);
    }

    public static function descricao(string $codigo): ?string
    {
        return self::todos()[$codigo] ?? null;
    }

    /** Busca por código (prefixo) ou por palavras da descrição, sem acento e sem maiúsculas. Sem texto, devolve as primeiras opções. */
    public static function buscar(string $termo, int $limite = 30): array
    {
        $termo = self::normalizar($termo);
        $item = fn ($c, $d) => ['codigo' => (string) $c, 'descricao' => $d];

        if ($termo === '') {
            return collect(self::todos())->take(50)->map(fn ($d, $c) => $item($c, $d))->values()->all();
        }

        $porCodigo = ctype_digit(str_replace('.', '', $termo));
        $digitos = str_replace('.', '', $termo);
        $palavras = array_filter(explode(' ', $termo));
        $achados = [];

        foreach (self::todos() as $codigo => $descricao) {
            $codigo = (string) $codigo;
            if ($porCodigo) {
                $ok = str_starts_with($codigo, $digitos);
            } else {
                $texto = self::normalizar($descricao);
                $ok = true;
                foreach ($palavras as $p) {
                    if (! str_contains($texto, $p)) {
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) {
                $achados[] = $item($codigo, $descricao);
                if (count($achados) >= $limite) {
                    break;
                }
            }
        }

        return $achados;
    }

    private static function normalizar(string $s): string
    {
        return preg_replace('/\s+/', ' ', trim(\Illuminate\Support\Str::of($s)->ascii()->lower()->toString()));
    }
}
