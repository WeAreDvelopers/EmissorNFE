<?php

namespace App\Console\Commands;

use App\Services\Pagamentos\Gateways\InterGateway;
use Illuminate\Console\Command;

/** Uso: php artisan inter:webhook   (rodar uma vez por ambiente, e ao trocar o domínio) */
class RegistrarWebhookInter extends Command
{
    protected $signature = 'inter:webhook';

    protected $description = 'Cadastra no Banco Inter a URL do webhook Pix da chave configurada';

    public function handle(InterGateway $inter): int
    {
        $url = route('webhooks.pagamentos', ['token' => config('services.inter.webhook_token')]);

        $inter->registrarWebhook($url);
        $this->info("Webhook cadastrado: {$url}");

        return self::SUCCESS;
    }
}
