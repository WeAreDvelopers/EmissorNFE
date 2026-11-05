<?php

namespace App\Services\Nfse;

use App\Enums\StatusNota;
use App\Jobs\EmitirNfseJob;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Services\Creditos\CarteiraService;

/**
 * Passo "Enviar": só aqui o crédito é reservado e a nota entra na fila de emissão.
 * Antes disso a nota é só rascunho conferido (ou inválida), sem custo.
 */
class EnvioDeNotas
{
    public function __construct(private CarteiraService $creditos) {}

    /** Reserva 1 crédito e enfileira. Sem saldo: fica "Aguardando crédito" (volta sozinha após a recarga). */
    public function enviarNota(NotaFiscal $nota): void
    {
        if (! $this->creditos->reservar($nota)) {
            $nota->update(['status' => StatusNota::AguardandoCredito, 'erros' => null]);

            return;
        }

        $nota->update(['status' => StatusNota::Pendente, 'erros' => null]);
        EmitirNfseJob::dispatch($nota);
    }

    /** Envia todos os rascunhos da importação. As inválidas continuam esperando correção. */
    public function enviarImportacao(Importacao $importacao): void
    {
        $importacao->update(['status' => 'enviando']);

        NotaFiscal::where('importacao_id', $importacao->id)
            ->where('status', StatusNota::Rascunho)
            ->orderBy('linha_planilha')
            ->eachById(fn (NotaFiscal $nota) => $this->enviarNota($nota));

        $importacao->update(['status' => 'enviada']);
    }
}
