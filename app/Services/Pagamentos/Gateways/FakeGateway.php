<?php

namespace App\Services\Pagamentos\Gateways;

use App\Enums\StatusPagamento;
use App\Models\Conta;
use App\Models\Pagamento;
use App\Services\Pagamentos\CobrancaPix;
use App\Services\Pagamentos\Contracts\GatewayPagamento;
use App\Services\Pagamentos\EventoPagamento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Gateway de DESENVOLVIMENTO: não fala com banco nenhum.
 * A cobrança só vira "paga" quando a tela de desenvolvimento chama pagar() (ver rota /dev/pix/{pagamento}/pagar).
 * Nunca use em produção (SAAS_GATEWAY=fake só vale com APP_ENV=local/testing).
 */
class FakeGateway implements GatewayPagamento
{
    public function nome(): string
    {
        return 'fake';
    }

    public function suportaSplit(): bool
    {
        return false;
    }

    public function garantirCliente(Conta $conta): ?string
    {
        return null;
    }

    public function criarCobrancaPix(Pagamento $pagamento, ?string $clienteId): CobrancaPix
    {
        abort_unless(app()->environment('local', 'testing'), 500, 'Gateway fake só pode ser usado em ambiente local.');

        $id = 'fake'.Str::lower(Str::random(26));
        $valor = number_format($pagamento->valor_centavos / 100, 2, '.', '');

        return new CobrancaPix(
            gatewayId: $id,
            copiaCola: "00020126FAKE{$id}5204000053039865406{$valor}5802BR5909DEV FAKE6009SAO PAULO62070503***6304ABCD",
            qrBase64: null,
            expiraEm: now()->addMinutes((int) config('saas.pix_validade_minutos', 30)),
        );
    }

    public function consultar(string $gatewayId): EventoPagamento
    {
        $pago = Cache::get("fake_pix:{$gatewayId}", false);

        return new EventoPagamento(
            eventoId: "fake-{$gatewayId}-".($pago ? 'pago' : 'pendente'),
            tipo: 'consulta',
            gatewayId: $gatewayId,
            status: $pago ? StatusPagamento::Pago : StatusPagamento::Pendente,
        );
    }

    public function lerWebhook(Request $request): ?array
    {
        return null; // não há webhook no fake: quem "paga" é pagar()
    }

    /** Simula o cliente pagando o Pix. */
    public static function pagar(string $gatewayId): void
    {
        Cache::forever("fake_pix:{$gatewayId}", true);
    }
}
