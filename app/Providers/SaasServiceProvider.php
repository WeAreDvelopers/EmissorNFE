<?php

namespace App\Providers;

use App\Enums\Papel;
use App\Jobs\ConciliarPagamentosJob;
use App\Jobs\ExpirarCreditosJob;
use App\Models\User;
use App\Services\Pagamentos\Contracts\GatewayPagamento;
use App\Services\Pagamentos\Gateways\AsaasGateway;
use App\Services\Pagamentos\Gateways\InterGateway;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Registre em bootstrap/providers.php. */
class SaasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Uma conta ativa por requisição/job
        $this->app->scoped(ContaAtual::class);

        $this->app->bind(GatewayPagamento::class, fn () => match (config('saas.gateway')) {
            'inter' => new InterGateway(),   // Pix direto no Banco Inter PJ (sem split)
            'asaas' => new AsaasGateway(),   // gateway com subcontas/split (white-label com divisão)
            'fake'  => new \App\Services\Pagamentos\Gateways\FakeGateway(), // só desenvolvimento (APP_ENV=local)
        });
    }

    public function boot(): void
    {
        // Cada permissão da matriz vira um Gate; admin da plataforma pode tudo
        Gate::before(fn (User $user) => $user->admin_plataforma ? true : null);
        foreach (Papel::permissoes() as $permissao) {
            Gate::define($permissao, fn (User $user) => $user->pode($permissao));
        }

        // Limite de envios à SEFIN, separado por emitente (um cliente grande não trava os outros)
        RateLimiter::for('sefin', fn ($job) => Limit::perMinute(config('nfse.limite_por_minuto'))
            ->by('emitente:'.$job->nota->emitente_id));

        $this->callAfterResolving(Schedule::class, function (Schedule $s) {
            $s->job(new ConciliarPagamentosJob)->hourly();
            $s->job(new ExpirarCreditosJob)->dailyAt('03:10');
            $s->command('nfse:conciliar')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
