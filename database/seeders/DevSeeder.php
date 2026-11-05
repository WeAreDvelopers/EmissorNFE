<?php

namespace Database\Seeders;

use App\Models\Revenda;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * SÓ DESENVOLVIMENTO: pacotes com preços FICTÍCIOS (os reais ainda estão em "Decisões pendentes")
 * e um usuário de teste. Rodar: php artisan db:seed --class=DevSeeder
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment('local', 'testing'), 403, 'DevSeeder só roda em ambiente local.');

        $this->call(SaasSeeder::class);
        $revenda = Revenda::where('padrao', true)->firstOrFail();

        foreach ([['Inicial', 50, 4900], ['Profissional', 200, 14900], ['Escritório', 1000, 59000]] as $i => [$nome, $creditos, $preco]) {
            $revenda->pacotes()->updateOrCreate(
                ['nome' => $nome],
                ['creditos' => $creditos, 'preco_centavos' => $preco, 'validade_dias' => 365, 'ativo' => true, 'ordem' => $i + 1],
            );
        }

        User::firstOrCreate(['email' => 'teste@local.test'], ['name' => 'Usuário de Teste', 'password' => Hash::make('senha-teste-123')]);
    }
}
