<?php

namespace App\Models;

use App\Enums\Papel;
use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;

class Convite extends Model
{
    use PertenceAConta;

    protected $fillable = ['conta_id', 'convidado_por', 'email', 'papel', 'token', 'expira_em', 'aceito_em'];

    protected $casts = [
        'papel'     => Papel::class,
        'expira_em' => 'datetime',
        'aceito_em' => 'datetime',
    ];

    public function valido(): bool
    {
        return ! $this->aceito_em && $this->expira_em->isFuture();
    }
}
