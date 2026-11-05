<?php

namespace App\Jobs;

use App\Enums\StatusNota;
use App\Models\NotaFiscal;
use App\Services\Creditos\CarteiraService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Depois de uma recarga: devolve à fila as notas que esperavam saldo, na ordem de importação. */
class LiberarNotasAguardandoCreditoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $contaId) {}

    public function uniqueId(): string
    {
        return (string) $this->contaId;
    }

    public function handle(CarteiraService $carteira): void
    {
        NotaFiscal::semIsolamento()
            ->where('conta_id', $this->contaId)
            ->where('status', StatusNota::AguardandoCredito)
            ->eachById(function (NotaFiscal $nota) use ($carteira) {
                if (! $carteira->reservar($nota)) {
                    return false; // acabou o saldo de novo: para aqui
                }

                $nota->update(['status' => StatusNota::Pendente]);
                EmitirNfseJob::dispatch($nota);
            });
        // eachById: pagina pelo id, então mudar o status durante o laço não pula linhas
    }
}
