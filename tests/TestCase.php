<?php

namespace Tests;

use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // O IdentificarRevenda exige a revenda padrão; todo teste com banco limpo parte dela.
    protected bool $seed = true;
    protected string $seeder = \Database\Seeders\SaasSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Support\GeraPlanilha::$clienteCompleto = true;   // estado estático: cada teste parte do padrão
    }

    /**
     * Em produção cada requisição começa SEM conta ativa. Nos testes o mesmo processo atende várias
     * requisições e a conta ativa da anterior "vazava", escondendo erros de ordem de middleware
     * (ex.: carregar o modelo da URL antes de definir a conta). Zerar aqui reproduz o mundo real.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app(ContaAtual::class)->definir(null);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
