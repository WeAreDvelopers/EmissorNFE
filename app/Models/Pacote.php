<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pacote extends Model
{
    protected $fillable = ['revenda_id', 'nome', 'creditos', 'preco_centavos', 'validade_dias', 'ativo', 'ordem'];

    protected $casts = ['ativo' => 'boolean'];

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    public function precoPorNota(): float
    {
        return $this->preco_centavos / 100 / max(1, $this->creditos);
    }
}
