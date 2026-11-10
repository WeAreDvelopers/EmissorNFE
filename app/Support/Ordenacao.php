<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Ordenação clicável das tabelas. A coluna vem da URL (?ordem=...&dir=asc|desc), mas só vale se estiver
 * no mapa da tela (nome na URL => coluna do banco): nunca texto livre no SQL.
 */
class Ordenacao
{
    /** Aplica a ordem escolhida (substitui a ordem padrão da consulta) e devolve [ordem, dir] para o cabeçalho. */
    public static function aplicar($consulta, Request $request, array $mapa, string $desempate = 'id'): array
    {
        $ordem = array_key_exists((string) $request->query('ordem'), $mapa) ? (string) $request->query('ordem') : null;
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        if ($ordem) {
            $consulta->reorder()->orderBy($mapa[$ordem], $dir)->orderBy($desempate);
        }

        return [$ordem, $dir];
    }
}
