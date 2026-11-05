<?php

namespace App\Jobs;

use App\Models\Importacao;
use App\Services\Nfse\EnvioDeNotas;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Reserva os créditos e enfileira cada nota conferida. Pode demorar em lotes grandes, por isso roda na fila. */
class EnviarImportacaoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public Importacao $importacao) {}

    public function handle(EnvioDeNotas $envio): void
    {
        app(ContaAtual::class)->como($this->importacao->conta_id, fn () => $envio->enviarImportacao($this->importacao));
    }
}
