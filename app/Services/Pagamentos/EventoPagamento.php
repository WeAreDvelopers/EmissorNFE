<?php

namespace App\Services\Pagamentos;

use App\Enums\StatusPagamento;

/** Evento já traduzido do formato do gateway para o nosso. */
final class EventoPagamento
{
    public function __construct(
        public readonly string $eventoId,      // id único do evento (idempotência do webhook)
        public readonly string $tipo,          // nome original do evento no gateway
        public readonly string $gatewayId,     // id da cobrança no gateway
        public readonly ?StatusPagamento $status, // null = evento que não muda nada para nós
        public readonly array $bruto = [],
    ) {}
}
