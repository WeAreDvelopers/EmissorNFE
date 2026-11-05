<?php

namespace App\Enums;

enum StatusPagamento: string
{
    case Pendente  = 'pendente';
    case Pago      = 'pago';
    case Expirado  = 'expirado';
    case Devolvido = 'devolvido';
    case Falhou    = 'falhou';
}
