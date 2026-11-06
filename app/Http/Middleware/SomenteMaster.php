<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Área master (plataforma): só quem é administrador da plataforma entra.
 * Quem não é recebe 404, para a área nem parecer existir. A checagem é aqui, na rota: esconder o menu não protege.
 */
class SomenteMaster
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->admin_plataforma, 404);

        return $next($request);
    }
}
