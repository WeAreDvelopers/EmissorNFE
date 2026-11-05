<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo da conta. Só o CarteiraService altera (sempre com lock + movimento no extrato).
 * Não usa PertenceAConta porque o serviço trava a linha por conta_id explicitamente.
 */
class Carteira extends Model
{
    protected $fillable = ['conta_id', 'disponivel', 'reservado'];

    public function conta(): BelongsTo
    {
        return $this->belongsTo(Conta::class);
    }
}
