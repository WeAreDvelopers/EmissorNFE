<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvento extends Model
{
    protected $fillable = ['gateway', 'evento_id', 'tipo', 'conteudo', 'processado_em'];

    protected $casts = [
        'conteudo'      => 'array',
        'processado_em' => 'datetime',
    ];
}
