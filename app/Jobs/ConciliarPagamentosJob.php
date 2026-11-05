<?php

namespace App\Jobs;

use App\Enums\StatusPagamento;
use App\Models\Pagamento;
use App\Services\Pagamentos\Contracts\GatewayPagamento;
use App\Services\Pagamentos\PagamentoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Agendar de hora em hora. Rede de segurança para webhook perdido:
 * consulta no gateway os Pix pendentes há mais de 15 min (até 3 dias).
 * Também expira os que passaram do prazo sem pagamento.
 */
class ConciliarPagamentosJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(GatewayPagamento $gateway, PagamentoService $pagamentos): void
    {
        Pagamento::semIsolamento()
            ->where('status', StatusPagamento::Pendente)
            ->whereNotNull('gateway_id')
            ->whereBetween('created_at', [now()->subDays(3), now()->subMinutes(15)])
            ->eachById(function (Pagamento $p) use ($gateway, $pagamentos) {
                try {
                    $evento = $gateway->consultar($p->gateway_id);
                    $status = $evento->status
                        ?? ($p->expira_em?->isPast() ? StatusPagamento::Expirado : null);

                    $pagamentos->aplicarStatus($p->gateway_id, $status);
                } catch (Throwable $e) {
                    report($e); // tenta de novo na próxima hora
                }
            });
    }
}
