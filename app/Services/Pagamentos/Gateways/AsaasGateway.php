<?php

namespace App\Services\Pagamentos\Gateways;

use App\Enums\StatusPagamento;
use App\Models\Conta;
use App\Models\Pagamento;
use App\Services\Pagamentos\CobrancaPix;
use App\Services\Pagamentos\Contracts\GatewayPagamento;
use App\Services\Pagamentos\EventoPagamento;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Driver Asaas (API v3).
 *
 * ⚠️ CONFERIR na documentação atual do Asaas antes de produção:
 *    URLs de sandbox/produção, nomes dos eventos de webhook, cabeçalho do token
 *    do webhook e o formato do split (walletId + fixedValue).
 */
class AsaasGateway implements GatewayPagamento
{
    // Eventos que interessam (demais são ignorados com 200)
    private const EVENTOS = [
        'PAYMENT_RECEIVED'  => StatusPagamento::Pago,
        'PAYMENT_CONFIRMED' => StatusPagamento::Pago,
        'PAYMENT_OVERDUE'   => StatusPagamento::Expirado,
        'PAYMENT_DELETED'   => StatusPagamento::Expirado,
        'PAYMENT_REFUNDED'  => StatusPagamento::Devolvido,
    ];

    public function nome(): string
    {
        return 'asaas';
    }

    public function suportaSplit(): bool
    {
        return true;
    }

    public function garantirCliente(Conta $conta): ?string
    {
        if ($conta->gateway_cliente_id) {
            return $conta->gateway_cliente_id;
        }

        $id = $this->http()->post('/customers', [
            'name'              => $conta->nome,
            'cpfCnpj'           => $conta->documento,
            'email'             => $conta->email_financeiro,
            'externalReference' => 'conta-'.$conta->id,
        ])->throw()->json('id');

        $conta->update(['gateway_cliente_id' => $id]);

        return $id;
    }

    public function criarCobrancaPix(Pagamento $pagamento, ?string $clienteId): CobrancaPix
    {
        $corpo = [
            'customer'          => $clienteId,
            'billingType'       => 'PIX',
            'value'             => round($pagamento->valor_centavos / 100, 2),
            'dueDate'           => now()->toDateString(),
            'description'       => "Créditos de emissão NFS-e ({$pagamento->creditos} notas)",
            'externalReference' => 'pagamento-'.$pagamento->id,
        ];

        // White-label (fase 4): parte do valor vai direto para a subconta da revenda
        if ($pagamento->split) {
            $corpo['split'] = collect($pagamento->split)->map(fn ($s) => [
                'walletId'   => $s['carteira'],
                'fixedValue' => round($s['valor_centavos'] / 100, 2),
            ])->all();
        }

        $cobranca = $this->http()->post('/payments', $corpo)->throw()->json();
        $qr = $this->http()->get("/payments/{$cobranca['id']}/pixQrCode")->throw()->json();

        return new CobrancaPix(
            gatewayId: $cobranca['id'],
            copiaCola: $qr['payload'] ?? throw new RuntimeException('Asaas não devolveu o copia-e-cola.'),
            qrBase64:  $qr['encodedImage'] ?? null,
            expiraEm:  now()->addMinutes(config('saas.pix_validade_minutos')),
        );
    }

    public function consultar(string $gatewayId): EventoPagamento
    {
        $p = $this->http()->get("/payments/{$gatewayId}")->throw()->json();

        $status = match ($p['status'] ?? null) {
            'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' => StatusPagamento::Pago,
            'OVERDUE'                                   => StatusPagamento::Expirado,
            'REFUNDED'                                  => StatusPagamento::Devolvido,
            default                                     => null,
        };

        return new EventoPagamento('consulta-'.$gatewayId.'-'.now()->timestamp, 'CONSULTA', $gatewayId, $status, $p);
    }

    public function lerWebhook(Request $request): ?array
    {
        $token = (string) config('services.asaas.webhook_token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('asaas-access-token'))) {
            return null; // não autenticado → 401 no controller
        }

        $tipo = (string) $request->input('event');
        $gatewayId = (string) $request->input('payment.id');

        return [new EventoPagamento(
            eventoId:  (string) ($request->input('id') ?? $tipo.'-'.$gatewayId),
            tipo:      $tipo,
            gatewayId: $gatewayId,
            status:    self::EVENTOS[$tipo] ?? null,
            bruto:     $request->all(),
        )];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.asaas.url'))
            ->withHeaders(['access_token' => config('services.asaas.key')])
            ->acceptJson()
            ->timeout(20);
    }
}
