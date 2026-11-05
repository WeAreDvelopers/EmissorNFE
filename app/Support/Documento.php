<?php

namespace App\Support;

/**
 * Dígito verificador de CPF/CNPJ.
 * A SEFIN rejeita DV inválido (E0080, E0188, E0206); conferimos já na importação.
 */
class Documento
{
    public static function valido(string $doc): bool
    {
        $doc = preg_replace('/\D/', '', $doc);

        return match (strlen($doc)) {
            11 => self::cpf($doc),
            14 => self::cnpj($doc),
            default => false,
        };
    }

    /** 00.000.000/0000-00 ou 000.000.000-00 (devolve o texto como veio se não tiver 11/14 dígitos). */
    public static function formatar(?string $doc): string
    {
        $d = preg_replace('/\D/', '', (string) $doc);

        return match (strlen($d)) {
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d),
            14 => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d),
            default => (string) $doc,
        };
    }

    private static function cpf(string $c): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $c[$i] * (($t + 1) - $i);
            }
            $dv = ((10 * $soma) % 11) % 10;
            if ((int) $c[$t] !== $dv) {
                return false;
            }
        }

        return true;
    }

    private static function cnpj(string $c): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $c)) {
            return false;
        }

        $pesos = [[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]];

        foreach ([12, 13] as $k => $pos) {
            $soma = 0;
            foreach ($pesos[$k] as $i => $peso) {
                $soma += (int) $c[$i] * $peso;
            }
            $resto = $soma % 11;
            $dv = $resto < 2 ? 0 : 11 - $resto;
            if ((int) $c[$pos] !== $dv) {
                return false;
            }
        }

        return true;
    }
}
