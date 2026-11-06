<?php

namespace App\Console\Commands;

use App\Services\Nfse\ConciliacaoNfse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ConciliarNotas extends Command
{
    protected $signature = 'nfse:conciliar';

    protected $description = 'Confere na SEFIN as notas com resultado incerto (falha de comunicação) e fecha as que já foram autorizadas';

    public function handle(ConciliacaoNfse $conciliacao): int
    {
        $autorizadas = 0;

        foreach ($conciliacao->candidatas() as $nota) {
            try {
                if ($conciliacao->conciliar($nota) === ConciliacaoNfse::AUTORIZADA) {
                    $autorizadas++;
                    Log::info('Conciliação: nota autorizada na SEFIN e recuperada', ['nota' => $nota->id]);
                }
            } catch (\Throwable $e) {
                Log::warning('Conciliação: não foi possível consultar a SEFIN', ['nota' => $nota->id, 'erro' => $e->getMessage()]);
            }
        }

        $this->info("{$autorizadas} nota(s) recuperada(s).");

        return self::SUCCESS;
    }
}
