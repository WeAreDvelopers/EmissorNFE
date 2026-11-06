<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Quem entra na área master: `php artisan master:definir email@exemplo.com` (ou --remover). */
class DefinirMaster extends Command
{
    protected $signature = 'master:definir {email : E-mail do usuário} {--remover : Tira o acesso master}';

    protected $description = 'Dá ou tira o acesso à área master (administrador da plataforma)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Não existe usuário com esse e-mail.');

            return self::FAILURE;
        }

        $user->forceFill(['admin_plataforma' => ! $this->option('remover')])->save();

        $this->info($user->admin_plataforma
            ? "{$user->email} agora é master."
            : "{$user->email} não é mais master.");
        $this->line('Masters atuais: '.User::where('admin_plataforma', true)->pluck('email')->implode(', '));

        return self::SUCCESS;
    }
}
