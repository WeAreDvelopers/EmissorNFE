<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\ContaAtual;
use Closure;
use Illuminate\Http\Request;

/**
 * Define a conta ativa a partir do usuário logado.
 * Se ele não pertence (mais) à conta salva, cai na primeira conta dele.
 * Sem nenhuma conta → tela de criar conta.
 */
class DefinirContaAtual
{
    public function __construct(private ContaAtual $contaAtual) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $contaId = $user->conta_atual_id;

            if (! $contaId || ! $user->contas()->whereKey($contaId)->exists()) {
                $contaId = $user->contas()->value('contas.id');
                $user->forceFill(['conta_atual_id' => $contaId])->save();
            }

            if (! $contaId && ! $request->routeIs('contas.criar', 'contas.store', 'convites.*', 'logout')) {
                return redirect()->route('contas.criar');
            }

            $this->contaAtual->definir($contaId);
        }

        return $next($request);
    }
}
