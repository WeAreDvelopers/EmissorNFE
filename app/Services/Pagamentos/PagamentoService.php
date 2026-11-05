<?php

namespace App\Services\Pagamentos;

use App\Enums\StatusPagamento;
use App\Enums\TipoMovimento;
use App\Jobs\LiberarNotasAguardandoCreditoJob;
use App\Models\Conta;
use App\Models\Pacote;
use App\Models\Pagamento;
use App\Models\WebhookEvento;
use App\Services\Creditos\CarteiraService;
use App\Services\Pagamentos\Contracts\GatewayPagamento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PagamentoService
{
    public function __construct(
        private GatewayPagamento $gateway,
        private CarteiraService $carteira,
    ) {}

    /** 1) Usuário escolhe o pacote → cobrança Pix pendente. */
    public function iniciarCompra(Conta $conta, Pacote $pacote, ?int $userId = null): Pagamento
    {
        // (int): drivers de banco podem devolver ids como string; ativo null = padrão do banco (true)
        if ((int) $pacote->revenda_id !== (int) $conta->revenda_id || $pacote->ativo === false) {
            throw new InvalidArgumentException('Pacote indisponível para esta conta.');
        }

        $pagamento = Pagamento::semIsolamento()->create([
            'conta_id'       => $conta->id,
            'pacote_id'      => $pacote->id,
            'user_id'        => $userId,
            'valor_centavos' => $pacote->preco_centavos,
            'creditos'       => $pacote->creditos,
            'validade_dias'  => $pacote->validade_dias,
            'status'         => StatusPagamento::Pendente,
            'gateway'        => $this->gateway->nome(),
            'split'          => $this->split($conta, $pacote),
        ]);

        $cobranca = $this->gateway->criarCobrancaPix($pagamento, $this->gateway->garantirCliente($conta));

        $pagamento->update([
            'gateway_id'     => $cobranca->gatewayId,
            'pix_copia_cola' => $cobranca->copiaCola,
            'pix_qr_base64'  => $cobranca->qrBase64,
            'expira_em'      => $cobranca->expiraEm,
        ]);

        return $pagamento;
    }

    /**
     * 2) Webhook → o evento é só um AVISO. O status que vale é o consultado na fonte
     * (protege contra webhook forjado, que é o risco num banco sem assinatura de webhook).
     * Evento já processado é ignorado; evento que falhou no meio pode ser reprocessado.
     */
    public function processarEvento(EventoPagamento $evento): void
    {
        try {
            $registro = WebhookEvento::firstOrCreate(
                ['gateway' => $this->gateway->nome(), 'evento_id' => $evento->eventoId],
                ['tipo' => $evento->tipo, 'conteudo' => $evento->bruto],
            );
        } catch (UniqueConstraintViolationException) {
            return; // outro worker está processando o mesmo evento agora
        }

        if ($registro->processado_em) {
            return; // já recebido e aplicado antes
        }

        $confirmado = $this->gateway->consultar($evento->gatewayId);
        $this->aplicarStatus($evento->gatewayId, $confirmado->status);

        $registro->update(['processado_em' => now()]);
    }

    public function aplicarStatus(string $gatewayId, ?StatusPagamento $status): void
    {
        if (! $status) {
            return;
        }

        $liberar = DB::transaction(function () use ($gatewayId, $status) {
            $pagamento = Pagamento::semIsolamento()->where('gateway_id', $gatewayId)->lockForUpdate()->first();
            if (! $pagamento) {
                return null;
            }

            // Pago: só uma vez, só a partir de pendente/expirado (Pix pago depois de "vencer" ainda vale)
            if ($status === StatusPagamento::Pago
                && in_array($pagamento->status, [StatusPagamento::Pendente, StatusPagamento::Expirado], true)) {
                $pagamento->update(['status' => StatusPagamento::Pago, 'pago_em' => now()]);
                $this->carteira->creditar(
                    $pagamento->conta_id, $pagamento->creditos, TipoMovimento::Compra,
                    $pagamento, $pagamento->validade_dias, "Pacote {$pagamento->pacote?->nome}", $pagamento->user_id,
                );

                return $pagamento->conta_id;
            }

            if ($status === StatusPagamento::Devolvido && $pagamento->status === StatusPagamento::Pago) {
                $pagamento->update(['status' => StatusPagamento::Devolvido]);
                $this->carteira->debitarDevolucao($pagamento);
            }

            if ($status === StatusPagamento::Expirado && $pagamento->status === StatusPagamento::Pendente) {
                $pagamento->update(['status' => StatusPagamento::Expirado]);
            }

            return null;
        });

        if ($liberar) {
            LiberarNotasAguardandoCreditoJob::dispatch($liberar)->afterCommit();
        }
    }

    /**
     * Fase 4: no pagamento de um cliente de revenda, a plataforma recebe
     * o atacado e a revenda recebe o resto (split no Pix).
     * Venda direta (revenda padrão) não tem split.
     */
    private function split(Conta $conta, Pacote $pacote): ?array
    {
        $revenda = $conta->revenda;
        if ($revenda->padrao || ! $revenda->gateway_carteira_id) {
            return null;
        }

        // Banco direto (Inter) não divide o Pix: a revenda deve operar no modelo atacado
        // (compra créditos da plataforma e revende). Ver documento de arquitetura, fase 4.
        if (! $this->gateway->suportaSplit()) {
            throw new InvalidArgumentException('O meio de pagamento atual não divide o Pix. Revendas devem usar o modelo atacado.');
        }

        $atacado = $revenda->preco_atacado_credito_centavos * $pacote->creditos;
        $parteRevenda = $pacote->preco_centavos - $atacado;

        if ($parteRevenda < 0) {
            throw new InvalidArgumentException('Pacote abaixo do preço de atacado.');
        }

        // O Asaas credita a conta principal (plataforma) e repassa o split para a subconta
        return $parteRevenda > 0
            ? [['carteira' => $revenda->gateway_carteira_id, 'valor_centavos' => $parteRevenda]]
            : null;
    }
}
