<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use App\Support\Tenancy\ContaAtual;
use App\Support\Tenancy\EmitenteAtual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Registro imutável de quem fez o quê na conta. Nunca guarda senhas, certificados nem conteúdo de arquivos. */
class Auditoria extends Model
{
    use PertenceAConta;

    protected $table = 'auditoria';

    public const UPDATED_AT = null;

    protected $fillable = ['conta_id', 'emitente_id', 'user_id', 'acao', 'descricao', 'detalhes', 'ip'];

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
        'clientes_importados'   => 'Clientes importados por planilha',
        'papel_alterado'        => 'Papel de usuário alterado',
        'usuario_removido'      => 'Usuário removido',
    ];

    /** Ações da conta toda (não são de uma empresa): aparecem para qualquer empresa selecionada. */
    public const ACOES_DA_CONTA = ['usuario_convidado', 'papel_alterado', 'usuario_removido'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A auditoria é imutável.'));
        static::deleting(fn () => throw new LogicException('A auditoria é imutável.'));
    }

    /** Empresa do registro: a informada; senão a da nota, lote ou cliente citados; senão a empresa selecionada no topo. */
    private static function empresaDoRegistro(string $acao, array $detalhes): ?int
    {
        if (in_array($acao, self::ACOES_DA_CONTA, true)) {
            return null;
        }
        $id = match (true) {
            isset($detalhes['nota'])    => NotaFiscal::semIsolamento()->whereKey($detalhes['nota'])->value('emitente_id'),
            isset($detalhes['lote'])    => Importacao::semIsolamento()->whereKey($detalhes['lote'])->value('emitente_id'),
            isset($detalhes['tomador']) => Tomador::semIsolamento()->whereKey($detalhes['tomador'])->value('emitente_id'),
            default                     => null,
        };

        return $id ?: app(EmitenteAtual::class)->id();
    }

    public static function registrar(string $acao, string $descricao, array $detalhes = [], ?int $contaId = null, ?int $emitenteId = null): void
    {
        $contaId ??= app(ContaAtual::class)->id();
        if (! $contaId) {
            return;   // sem conta não há onde registrar (ex.: ação da área master, que tem a sua própria trilha)
        }

        static::create([
            'conta_id'  => $contaId,
            'emitente_id' => $emitenteId ?? self::empresaDoRegistro($acao, $detalhes),
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
