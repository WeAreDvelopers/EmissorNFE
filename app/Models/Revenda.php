<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Revenda extends Model
{
    protected $fillable = [
        'nome', 'slug', 'dominio', 'marca', 'preco_atacado_credito_centavos',
        'gateway_carteira_id', 'padrao', 'status',
    ];

    protected $casts = [
        'marca'  => 'array',
        'padrao' => 'boolean',
    ];

    public function contas(): HasMany
    {
        return $this->hasMany(Conta::class);
    }

    public function pacotes(): HasMany
    {
        return $this->hasMany(Pacote::class)->where('ativo', true)->orderBy('ordem');
    }

    public static function padrao(): self
    {
        return static::where('padrao', true)->firstOrFail();
    }
}
