<?php

namespace App\Models\Concerns;

use App\Models\Conta;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Isolamento por conta:
 * - toda consulta é filtrada pela conta ativa;
 * - todo registro novo recebe o conta_id da conta ativa;
 * - sem conta ativa, a consulta não retorna nada (falha fechada, nunca aberta).
 *
 * Painel da plataforma usa Model::semIsolamento() explicitamente.
 */
trait PertenceAConta
{
    public static function bootPertenceAConta(): void
    {
        static::addGlobalScope('conta', function (Builder $q) {
            $id = app(ContaAtual::class)->id();
            $q->where($q->getModel()->qualifyColumn('conta_id'), $id ?? 0);
        });

        static::creating(function ($model) {
            if (! $model->conta_id) {
                $model->conta_id = app(ContaAtual::class)->id()
                    ?? throw new LogicException('Criando '.class_basename($model).' sem conta ativa.');
            }
        });
    }

    public static function semIsolamento(): Builder
    {
        return static::withoutGlobalScope('conta');
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(Conta::class);
    }
}
