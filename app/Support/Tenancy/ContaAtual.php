<?php

namespace App\Support\Tenancy;

use App\Models\Conta;
use Closure;

/**
 * Guarda a conta ativa da requisição ou do job (registrada como singleton).
 * Tudo que usa PertenceAConta é filtrado por ela.
 */
class ContaAtual
{
    private ?int $id = null;

    public function definir(?int $contaId): void
    {
        $this->id = $contaId;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function conta(): ?Conta
    {
        return $this->id ? Conta::find($this->id) : null;
    }

    /** Executa um trecho "dentro" de outra conta (jobs, webhooks) e restaura a anterior. */
    public function como(int $contaId, Closure $fn): mixed
    {
        $anterior = $this->id;
        $this->id = $contaId;

        try {
            return $fn();
        } finally {
            $this->id = $anterior;
        }
    }
}
