<?php

namespace App\Enums;

/** Papel do usuário dentro de UMA conta (o mesmo usuário pode ter papéis diferentes em contas diferentes). */
enum Papel: string
{
    case Dono       = 'dono';
    case Financeiro = 'financeiro';
    case Operador   = 'operador';
    case Leitura    = 'leitura';

    /** Matriz de permissões. Os nomes viram Gates (ver AppServiceProvider no README). */
    private const PERMISSOES = [
        'gerenciar-conta'     => [self::Dono],
        'gerenciar-usuarios'  => [self::Dono],
        'gerenciar-emitentes' => [self::Dono],
        'comprar-creditos'    => [self::Dono, self::Financeiro],
        'ver-financeiro'      => [self::Dono, self::Financeiro],
        'emitir'              => [self::Dono, self::Operador],
        'cancelar-nota'       => [self::Dono],   // cancelar NFS-e é um ato fiscal sem volta: só o dono
        'consultar'           => [self::Dono, self::Financeiro, self::Operador, self::Leitura],
    ];

    public function pode(string $permissao): bool
    {
        return in_array($this, self::PERMISSOES[$permissao] ?? [], true);
    }

    public static function permissoes(): array
    {
        return array_keys(self::PERMISSOES);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
