<?php

namespace App\Services\Creditos;

use App\Enums\TipoMovimento;
use App\Models\Carteira;
use App\Models\Emitente;
use App\Models\LoteCredito;
use App\Models\MovimentoCredito;
use App\Models\NotaFiscal;
use App\Models\Pagamento;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Única porta de entrada para mexer em créditos.
 *
 * Regras:
 * - toda alteração trava a linha da carteira (lockForUpdate) e grava um movimento no extrato;
 * - a nota RESERVA ao entrar na fila, CONSOME quando autorizada, ESTORNA se rejeitada/falhou;
 * - cada reenvio é um novo "ciclo" da nota; reserva/consumo/estorno são únicos por nota + ciclo
 *   (índice único no banco), então job repetido nunca cobra duas vezes;
 * - lotes são consumidos por ordem de vencimento (FIFO).
 *
 * "quantidade" no extrato: + entra no saldo afetado, − sai dele.
 */
class CarteiraService
{
    /** Compra, bônus ou ajuste positivo: cria um lote e soma no disponível. */
    public function creditar(
        int $contaId,
        int $quantidade,
        TipoMovimento $tipo,
        ?Pagamento $pagamento = null,
        ?int $validadeDias = null,
        ?string $descricao = null,
        ?int $userId = null,
    ): LoteCredito {
        if ($quantidade <= 0 || ! in_array($tipo, [TipoMovimento::Compra, TipoMovimento::Bonus, TipoMovimento::Ajuste], true)) {
            throw new InvalidArgumentException('Crédito inválido.');
        }

        return DB::transaction(function () use ($contaId, $quantidade, $tipo, $pagamento, $validadeDias, $descricao, $userId) {
            $carteira = $this->travar($contaId);

            $lote = LoteCredito::semIsolamento()->create([
                'conta_id'     => $contaId,
                'origem'       => $tipo->value,
                'pagamento_id' => $pagamento?->id,
                'quantidade'   => $quantidade,
                'restante'     => $quantidade,
                'expira_em'    => $validadeDias ? now()->addDays($validadeDias) : null,
            ]);

            $carteira->disponivel += $quantidade;
            $carteira->save();

            $this->registrar($carteira, $tipo, $quantidade, lote: $lote, pagamento: $pagamento, userId: $userId, descricao: $descricao);

            // Recarga que cobre o saldo negativo (após devolução de Pix) desbloqueia a conta
            if ($carteira->disponivel >= 0) {
                $carteira->conta()->where('status', 'bloqueada_saldo_negativo')->update(['status' => 'ativa']);
            }

            return $lote;
        });
    }

    /**
     * Reserva 1 crédito para a nota. Retorna false se não há saldo
     * (a nota fica "Aguardando crédito" e volta para a fila após a recarga).
     */
    public function reservar(NotaFiscal $nota): bool
    {
        // Homologação (modo teste) é grátis: não mexe na carteira, não gera movimento e nunca fica "aguardando crédito".
        // Como nunca há reserva, consumir() e estornar() também viram no-op para essas notas.
        if ($this->semCobranca($nota)) {
            return true;
        }

        return DB::transaction(function () use ($nota) {
            $carteira = $this->travar($nota->conta_id);
            $nota = NotaFiscal::semIsolamento()->lockForUpdate()->findOrFail($nota->id);

            if ($this->reservaAtiva($nota)) {
                return true; // idempotente: já reservado neste ciclo
            }

            if ($carteira->disponivel < 1) {
                return false;
            }

            $lote = LoteCredito::semIsolamento()
                ->where('conta_id', $nota->conta_id)
                ->where('restante', '>', 0)
                ->where(fn ($q) => $q->whereNull('expira_em')->orWhere('expira_em', '>', now()))
                ->orderByRaw('expira_em is null')   // os que vencem primeiro saem primeiro
                ->orderBy('expira_em')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $lote) {
                return false; // saldo sem lote válido (ex.: lote venceu e o job de expiração ainda não rodou)
            }

            $lote->decrement('restante');
            $nota->increment('ciclo_credito');

            $carteira->disponivel -= 1;
            $carteira->reservado += 1;
            $carteira->save();

            $this->registrar($carteira, TipoMovimento::Reserva, -1, lote: $lote, nota: $nota);

            return true;
        });
    }

    /**
     * A nota é isenta de cobrança? Emitente em ambiente de teste ou com a cobrança desligada pelo master.
     * (sem isolamento: roda também no worker, sem conta ativa)
     */
    public function semCobranca(NotaFiscal $nota): bool
    {
        $e = Emitente::semIsolamento()->whereKey($nota->emitente_id)->first(['ambiente', 'cobranca_desligada']);

        return $e !== null && ((int) $e->ambiente !== 1 || $e->cobranca_desligada);
    }

    /** NFS-e autorizada: o crédito reservado é consumido. */
    public function consumir(NotaFiscal $nota): void
    {
        $this->fecharReserva($nota, TipoMovimento::Consumo);
    }

    /** Nota rejeitada ou com falha definitiva: o crédito volta para o disponível e para o lote. */
    public function estornar(NotaFiscal $nota): void
    {
        $this->fecharReserva($nota, TipoMovimento::Estorno);
    }

    /** Job diário: lotes vencidos com saldo restante saem do disponível. */
    public function expirarLotes(): int
    {
        $expirados = 0;

        LoteCredito::semIsolamento()
            ->where('restante', '>', 0)
            ->whereNotNull('expira_em')
            ->where('expira_em', '<=', now())
            ->eachById(function (LoteCredito $lote) use (&$expirados) {
                DB::transaction(function () use ($lote, &$expirados) {
                    $carteira = $this->travar($lote->conta_id);
                    $lote = LoteCredito::semIsolamento()->lockForUpdate()->find($lote->id);

                    if ($lote->restante < 1) {
                        return;
                    }

                    $qtd = $lote->restante;
                    $lote->update(['restante' => 0]);
                    $carteira->disponivel -= $qtd;
                    $carteira->save();

                    $this->registrar($carteira, TipoMovimento::Expiracao, -$qtd, lote: $lote, descricao: 'Créditos vencidos');
                    $expirados += $qtd;
                });
            });

        return $expirados;
    }

    /**
     * Pix devolvido (estorno ou MED): tira os créditos da compra.
     * Se já foram usados, o disponível fica negativo e a conta é bloqueada para emitir.
     */
    public function debitarDevolucao(Pagamento $pagamento): void
    {
        DB::transaction(function () use ($pagamento) {
            $carteira = $this->travar($pagamento->conta_id);
            $lote = LoteCredito::semIsolamento()->where('pagamento_id', $pagamento->id)->lockForUpdate()->first();

            $lote?->update(['restante' => 0]);
            $carteira->disponivel -= $pagamento->creditos;
            $carteira->save();

            $this->registrar($carteira, TipoMovimento::Devolucao, -$pagamento->creditos, lote: $lote, pagamento: $pagamento, descricao: 'Pix devolvido');

            if ($carteira->disponivel < 0) {
                $carteira->conta()->update(['status' => 'bloqueada_saldo_negativo']);
            }
        });
    }

    public function saldo(int $contaId): Carteira
    {
        return Carteira::firstOrCreate(['conta_id' => $contaId]);
    }

    // ---------------------------------------------------------------------

    private function fecharReserva(NotaFiscal $nota, TipoMovimento $tipo): void
    {
        DB::transaction(function () use ($nota, $tipo) {
            $carteira = $this->travar($nota->conta_id);
            $nota = NotaFiscal::semIsolamento()->lockForUpdate()->findOrFail($nota->id);

            $reserva = $this->reservaAtiva($nota);
            if (! $reserva) {
                return; // idempotente: já consumido/estornado neste ciclo, ou nunca reservado
            }

            $carteira->reservado -= 1;

            if ($tipo === TipoMovimento::Estorno) {
                $carteira->disponivel += 1;
                LoteCredito::semIsolamento()->whereKey($reserva->lote_credito_id)->increment('restante');
            }

            $carteira->save();

            $this->registrar($carteira, $tipo, $tipo === TipoMovimento::Estorno ? 1 : -1, lote: null, nota: $nota, loteId: $reserva->lote_credito_id);
        });
    }

    /** Reserva do ciclo atual que ainda não foi consumida nem estornada. */
    private function reservaAtiva(NotaFiscal $nota): ?MovimentoCredito
    {
        if ($nota->ciclo_credito < 1) {
            return null;
        }

        $doCiclo = MovimentoCredito::semIsolamento()
            ->where('nota_fiscal_id', $nota->id)
            ->where('ciclo', $nota->ciclo_credito)
            ->get()
            ->keyBy(fn ($m) => $m->tipo->value);

        if (isset($doCiclo[TipoMovimento::Consumo->value]) || isset($doCiclo[TipoMovimento::Estorno->value])) {
            return null;
        }

        return $doCiclo[TipoMovimento::Reserva->value] ?? null;
    }

    private function travar(int $contaId): Carteira
    {
        Carteira::firstOrCreate(['conta_id' => $contaId]);

        return Carteira::where('conta_id', $contaId)->lockForUpdate()->firstOrFail();
    }

    private function registrar(
        Carteira $carteira,
        TipoMovimento $tipo,
        int $quantidade,
        ?LoteCredito $lote = null,
        ?NotaFiscal $nota = null,
        ?Pagamento $pagamento = null,
        ?int $userId = null,
        ?string $descricao = null,
        ?int $loteId = null,
    ): MovimentoCredito {
        return MovimentoCredito::semIsolamento()->create([
            'conta_id'        => $carteira->conta_id,
            'tipo'            => $tipo,
            'quantidade'      => $quantidade,
            'disponivel_apos' => $carteira->disponivel,
            'reservado_apos'  => $carteira->reservado,
            'lote_credito_id' => $lote?->id ?? $loteId,
            'nota_fiscal_id'  => $nota?->id,
            'pagamento_id'    => $pagamento?->id,
            'user_id'         => $userId ?? auth()->id(),
            'ciclo'           => $nota?->ciclo_credito ?? 1,
            'descricao'       => $descricao,
        ]);
    }
}
