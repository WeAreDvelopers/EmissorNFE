<?php

namespace App\Support\Tenancy;

use App\Models\Emitente;
use Closure;

/**
 * A empresa (emitente) que o usuário está administrando agora, escolhida no menu lateral.
 * Notas, importações e tomadores (clientes) só aparecem e só são criados dentro dela (trait PertenceAoEmitente).
 * Fora de requisição de tela (jobs, webhooks, painel master) fica vazia e nada é filtrado.
 */
class EmitenteAtual
{
    private ?int $id = null;

    private ?Emitente $cache = null;

    public function definir(?int $emitenteId): void
    {
        $this->id = $emitenteId;
        $this->cache = null;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function emitente(): ?Emitente
    {
        if (! $this->id) {
            return null;
        }

        return $this->cache ??= Emitente::find($this->id);
    }

    /** Executa um trecho "dentro" de outra empresa e restaura a anterior. */
    public function como(?int $emitenteId, Closure $fn): mixed
    {
        $anterior = $this->id;
        $this->definir($emitenteId);

        try {
            return $fn();
        } finally {
            $this->definir($anterior);
        }
    }
}
