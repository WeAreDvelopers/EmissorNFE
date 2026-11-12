<?php

namespace App\Http\Middleware;

use App\Models\Emitente;
use App\Support\Tenancy\ContaAtual;
use App\Support\Tenancy\EmitenteAtual;
use Closure;
use Illuminate\Http\Request;

/**
 * Define a conta ativa a partir do usuário logado.
 * Se ele não pertence (mais) à conta salva, cai na primeira conta dele.
 * Sem nenhuma conta → tela de criar conta.
 */
class DefinirContaAtual
{
    public function __construct(private ContaAtual $contaAtual, private EmitenteAtual $emitenteAtual) {}

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

            // Empresa que está sendo administrada (menu lateral). Painel master não filtra por empresa.
            $this->emitenteAtual->definir(null);
            if ($contaId && ! $request->routeIs('master.*')) {
                $ids = Emitente::where('ativo', true)->orderBy('razao_social')->pluck('id');
                $escolhida = (int) $user->emitente_atual_id;
                $id = $ids->contains($escolhida) ? $escolhida : $ids->first();
                if ((int) $id !== $escolhida) {
                    $user->forceFill(['emitente_atual_id' => $id])->save();
                }
                $this->emitenteAtual->definir($id ? (int) $id : null);
            }
        }

        return $next($request);
    }
}
