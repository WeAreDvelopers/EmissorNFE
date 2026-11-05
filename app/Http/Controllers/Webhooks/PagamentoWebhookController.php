<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Pagamentos\Contracts\GatewayPagamento;
use App\Services\Pagamentos\PagamentoService;
use Illuminate\Http\Request;

/** POST /webhooks/pagamentos/{token} — sem CSRF, sem login; token secreto no caminho + confirmação na fonte. */
class PagamentoWebhookController extends Controller
{
    public function __invoke(Request $request, GatewayPagamento $gateway, PagamentoService $pagamentos)
    {
        $eventos = $gateway->lerWebhook($request);

        if ($eventos === null) {
            return response()->json(['erro' => 'não autorizado'], 401);
        }

        foreach ($eventos as $evento) {
            $pagamentos->processarEvento($evento); // confirma cada um na fonte antes de creditar
        }

        // Sempre 200 para eventos válidos (mesmo ignorados), senão o gateway reenvia sem parar
        return response()->json(['ok' => true]);
    }
}
