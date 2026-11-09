<?php

namespace App\Support;

/** Lista nacional de serviços (cTribNac, 6 dígitos). Fora dela a SEFIN responde E0310. */
class ListaServicoNacional
{
    private static ?array $lista = null;

    public static function todos(): array
    {
        return self::$lista ??= require resource_path('data/lista_servico_nacional.php');
    }

    public static function existe(string $codigo): bool
    {
        return isset(self::todos()[$codigo]);
    }

    public static function descricao(string $codigo): ?string
    {
        return self::todos()[$codigo] ?? null;
    }

    /** Busca por código (prefixo) ou por palavras da descrição, sem acento e sem maiúsculas. */
    public static function buscar(string $termo, int $limite = 20): array
    {
        $termo = self::normalizar($termo);
        if ($termo === '') {
            return [];
        }
        $palavras = array_filter(explode(' ', $termo));
        $achados = [];
        foreach (self::todos() as $codigo => $descricao) {
            $codigo = (string) $codigo;
            $texto = self::normalizar($descricao);
            $ok = ctype_digit($termo) ? str_starts_with($codigo, $termo) : true;
            if ($ok && ! ctype_digit($termo)) {
                foreach ($palavras as $p) {
                    if (! str_contains($texto, $p)) { $ok = false; break; }
                }
            }
            if ($ok) {
                $achados[] = ['codigo' => $codigo, 'descricao' => $descricao];
                if (count($achados) >= $limite) break;
            }
        }
        return $achados;
    }

    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','ç'=>'c']);
        return preg_replace('/\s+/', ' ', $s);
    }
}
