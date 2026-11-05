<?php

namespace App\Services\Pagamentos\Contracts;

use App\Models\Conta;
use App\Models\Pagamento;
use App\Services\Pagamentos\CobrancaPix;
use App\Services\Pagamentos\EventoPagamento;
use Illuminate\Http\Request;

/** Trocar de gateway/banco = escrever outro driver que implementa esta interface. */
interface GatewayPagamento
{
    public function nome(): string;

    /** Divide o Pix entre plataforma e revenda? (Asaas sim; banco direto, não). */
    public function suportaSplit(): bool;

    /** Cria (se o gateway exigir) o cliente e devolve o id dele; null se não existe esse conceito. */
    public function garantirCliente(Conta $conta): ?string;

    /** Cria a cobrança Pix e devolve copia-e-cola (+ QR, se o gateway gerar a imagem). */
    public function criarCobrancaPix(Pagamento $pagamento, ?string $clienteId): CobrancaPix;

    /** Consulta o status atual na fonte. É a ÚNICA base para creditar. */
    public function consultar(string $gatewayId): EventoPagamento;

    /**
     * Traduz o webhook em eventos. null = requisição não autenticada.
     * O status que vier aqui é só um aviso: o PagamentoService confirma com consultar().
     *
     * @return EventoPagamento[]|null
     */
    public function lerWebhook(Request $request): ?array;
}
