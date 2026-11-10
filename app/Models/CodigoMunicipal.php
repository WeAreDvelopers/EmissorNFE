<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Código complementar municipal (cTribMun) cadastrado para uma empresa, com a descrição da prefeitura. */
class CodigoMunicipal extends Model
{
    use PertenceAConta;

    protected $table = 'emitente_codigos_municipais';

    protected $fillable = ['conta_id', 'emitente_id', 'codigo', 'descricao', 'codigo_servico'];

    public function emitente(): BelongsTo
    {
        return $this->belongsTo(Emitente::class);
    }
}
