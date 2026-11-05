<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(prepend: [\App\Http\Middleware\IdentificarRevenda::class]);
        $middleware->alias(['conta' => \App\Http\Middleware\DefinirContaAtual::class]);
        // A conta ativa precisa existir ANTES de o Laravel carregar {importacao}, {nota}, {pagamento}… da URL:
        // esses modelos são filtrados pela conta, e sem ela a busca não acha nada (404).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\DefinirContaAtual::class,
        );
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
