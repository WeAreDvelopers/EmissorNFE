<?php

namespace App\Models\Concerns;

use App\Enums\Papel;
use App\Models\Conta;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Adicione ao App\Models\User:  use \App\Models\Concerns\TemContas;
 * e inclua no $fillable: 'telefone', 'conta_atual_id'.
 */
trait TemContas
{
    public function contas(): BelongsToMany
    {
        return $this->belongsToMany(Conta::class, 'conta_usuario')->withPivot('papel')->withTimestamps();
    }

    /** Papel do usuário na conta informada (ou na conta ativa). */
    public function papelNa(?int $contaId = null): ?Papel
    {
        $contaId ??= app(ContaAtual::class)->id();
        $papel = $this->contas()->whereKey($contaId)->first()?->pivot?->papel;

        return $papel ? Papel::from($papel) : null;
    }

    public function pode(string $permissao, ?int $contaId = null): bool
    {
        return (bool) $this->papelNa($contaId)?->pode($permissao);
    }

    public function trocarConta(int $contaId): void
    {
        abort_unless($this->contas()->whereKey($contaId)->exists(), 403);
        $this->forceFill(['conta_atual_id' => $contaId])->save();
    }
}
