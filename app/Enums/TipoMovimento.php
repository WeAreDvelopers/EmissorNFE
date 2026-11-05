<?php

namespace App\Enums;

enum TipoMovimento: string
{
    case Compra   = 'compra';     // + disponível (Pix confirmado)
    case Bonus    = 'bonus';      // + disponível (cadastro, cortesia)
    case Reserva  = 'reserva';    // disponível → reservado (nota entrou na fila)
    case Consumo  = 'consumo';    // − reservado (NFS-e autorizada)
    case Estorno  = 'estorno';    // reservado → disponível (rejeitada / falhou)
    case Expiracao = 'expiracao'; // − disponível (lote venceu)
    case Devolucao = 'devolucao'; // − disponível (Pix devolvido / MED)
    case Ajuste   = 'ajuste';     // ± disponível (manual, com motivo)
}
