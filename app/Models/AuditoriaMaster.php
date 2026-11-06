<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Registro imutável das consultas feitas na área master. */
class AuditoriaMaster extends Model
{
    protected $table = 'auditoria_master';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'acao', 'conta_id', 'detalhes', 'ip'];

    protected $casts = ['detalhes' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A auditoria é imutável.'));
        static::deleting(fn () => throw new LogicException('A auditoria é imutável.'));
    }

    public static function registrar(string $acao, ?int $contaId = null, array $detalhes = []): void
    {
        static::create([
            'user_id'  => auth()->id(),
            'acao'     => $acao,
            'conta_id' => $contaId,
            'detalhes' => $detalhes ?: null,
            'ip'       => request()->ip(),
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
