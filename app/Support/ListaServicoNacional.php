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
}
