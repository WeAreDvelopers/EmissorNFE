<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Registro imutável de quem fez o quê na conta. Nunca guarda senhas, certificados nem conteúdo de arquivos. */
class Auditoria extends Model
{
    use PertenceAConta;

    protected $table = 'auditoria';

    public const UPDATED_AT = null;

    protected $fillable = ['conta_id', 'user_id', 'acao', 'descricao', 'detalhes', 'ip'];

    protected $casts = ['detalhes' => 'array'];

    /** Nome de cada ação para a tela. */
    public const ACOES = [
        'empresa_criada'        => 'Empresa cadastrada',
        'empresa_alterada'      => 'Empresa alterada',
        'numeracao_ajustada'    => 'Numeração da DPS ajustada',
        'importacao_enviada'    => 'Lote enviado para emissão',
        'importacao_excluida'   => 'Lote excluído',
        'nota_corrigida'        => 'Nota corrigida',
        'nota_excluida'         => 'Linha excluída',
        'duplicidade_aprovada'  => 'Duplicidade aprovada',
        'nota_conciliada'       => 'Nota conferida na SEFIN',
        'email_enviado'         => 'E-mail enviado ao cliente',
        'nota_cancelada'        => 'NFS-e cancelada',
        'usuario_convidado'     => 'Usuário convidado',
        'papel_alterado'        => 'Papel de usuário alterado',
        'usuario_removido'      => 'Usuário removido',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A auditoria é imutável.'));
        static::deleting(fn () => throw new LogicException('A auditoria é imutável.'));
    }

    public static function registrar(string $acao, string $descricao, array $detalhes = [], ?int $contaId = null): void
    {
        $contaId ??= app(ContaAtual::class)->id();
        if (! $contaId) {
            return;   // sem conta não há onde registrar (ex.: ação da área master, que tem a sua própria trilha)
        }

        static::create([
            'conta_id'  => $contaId,
            'user_id'   => auth()->id(),
            'acao'      => $acao,
            'descricao' => mb_substr($descricao, 0, 300),
            'detalhes'  => $detalhes ?: null,
            'ip'        => request()->ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rotulo(): string
    {
        return self::ACOES[$this->acao] ?? $this->acao;
    }
}
