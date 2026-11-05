<?php

namespace App\Models;

use App\Enums\Papel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** O cliente que paga. Não usa PertenceAConta: ela É a conta. */
class Conta extends Model
{
    protected $fillable = [
        'revenda_id', 'nome', 'documento', 'email_financeiro',
        'gateway_cliente_id', 'limite_aviso_saldo', 'status',
    ];

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conta_usuario')->withPivot('papel')->withTimestamps();
    }

    public function carteira(): HasOne
    {
        return $this->hasOne(Carteira::class);
    }

    public function emitentes(): HasMany
    {
        return $this->hasMany(Emitente::class)->withoutGlobalScope('conta');
    }

    public function donos(): BelongsToMany
    {
        return $this->usuarios()->wherePivot('papel', Papel::Dono->value);
    }

    public function podeEmitir(): bool
    {
        return $this->status === 'ativa';
    }
}
