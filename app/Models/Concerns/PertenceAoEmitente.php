<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\EmitenteAtual;
use Illuminate\Database\Eloquent\Builder;

/**
 * Isolamento por empresa (emitente), dentro da conta:
 * - com uma empresa ativa (tela), toda consulta é filtrada por ela;
 * - registro novo sem emitente_id recebe o da empresa ativa;
 * - sem empresa ativa (jobs, webhooks, master) não filtra nada. O isolamento por conta continua valendo.
 *
 * Model::semIsolamento() (PertenceAConta) também tira este filtro.
 */
trait PertenceAoEmitente
{
    public static function bootPertenceAoEmitente(): void
    {
        static::addGlobalScope('emitente', function (Builder $q) {
            if ($id = app(EmitenteAtual::class)->id()) {
                $q->where($q->getModel()->qualifyColumn('emitente_id'), $id);
            }
        });

        static::creating(function ($model) {
            if (! $model->emitente_id && ($id = app(EmitenteAtual::class)->id())) {
                $model->emitente_id = $id;
            }
        });
    }

    public static function semEmitente(): Builder
    {
        return static::withoutGlobalScope('emitente');
    }
}
