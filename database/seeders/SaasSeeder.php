<?php

namespace Database\Seeders;

use App\Models\Revenda;
use Illuminate\Database\Seeder;

/** Cria a revenda padrão (venda direta). Pacotes: preencha nome, créditos e preço reais. */
class SaasSeeder extends Seeder
{
    public function run(): void
    {
        Revenda::firstOrCreate(['slug' => 'direta'], [
            'nome'   => env('SAAS_NOME_PRODUTO', '[Nome do produto]'),
            'padrao' => true,
            'status' => 'ativa',
        ]);

        // Exemplo de como cadastrar pacotes (preços a definir — ver "Decisões pendentes"):
        // $revenda->pacotes()->create(['nome' => 'Inicial', 'creditos' => 50, 'preco_centavos' => 0, 'validade_dias' => 365, 'ordem' => 1]);
    }
}
