<?php

namespace App\Models;

use App\Enums\TipoMovimento;
use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Linha do extrato. Imutável: só insert. */
class MovimentoCredito extends Model
{
    use PertenceAConta;

    protected $table = 'movimentos_credito';

    public const UPDATED_AT = null;

    protected $fillable = [
        'conta_id', 'tipo', 'quantidade', 'disponivel_apos', 'reservado_apos',
        'lote_credito_id', 'nota_fiscal_id', 'pagamento_id', 'user_id', 'ciclo', 'descricao',
    ];

    protected $casts = ['tipo' => TipoMovimento::class];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Extrato de créditos é imutável.'));
        static::deleting(fn () => throw new LogicException('Extrato de créditos é imutável.'));
    }
}
