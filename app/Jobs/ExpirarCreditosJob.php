<?php

namespace App\Jobs;

use App\Services\Creditos\CarteiraService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Agendar 1x por dia (madrugada). */
class ExpirarCreditosJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(CarteiraService $carteira): void
    {
        $carteira->expirarLotes();
    }
}
