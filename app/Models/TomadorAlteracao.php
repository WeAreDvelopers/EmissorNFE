<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha do histórico de um cliente. Só se acrescenta; nunca se altera. */
class TomadorAlteracao extends Model
{
    use PertenceAConta;

    protected $table = 'tomador_alteracoes';

    public const UPDATED_AT = null;

    protected $fillable = ['conta_id', 'tomador_id', 'user_id', 'importacao_id', 'origem', 'campos'];

    protected $casts = ['campos' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
