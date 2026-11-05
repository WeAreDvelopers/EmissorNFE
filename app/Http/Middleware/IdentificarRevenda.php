<?php

namespace App\Http\Middleware;

use App\Models\Revenda;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

/**
 * White-label (fase 4): descobre a revenda pelo domínio da requisição
 * e deixa a marca disponível para as views. Domínio desconhecido = revenda padrão.
 */
class IdentificarRevenda
{
    public function handle(Request $request, Closure $next)
    {
        $host = strtolower($request->getHost());

        $revenda = Cache::remember("revenda:host:{$host}", 300, fn () =>
            Revenda::where('dominio', $host)->where('status', 'ativa')->first()
            ?? Revenda::where('padrao', true)->firstOrFail()
        );

        app()->instance(Revenda::class, $revenda);
        View::share('revenda', $revenda);

        return $next($request);
    }
}
