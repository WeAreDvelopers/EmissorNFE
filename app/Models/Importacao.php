<?php

namespace App\Models;

use App\Enums\StatusNota;
use App\Models\Concerns\PertenceAConta;
use App\Models\Concerns\PertenceAoEmitente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Importacao extends Model
{
    use PertenceAConta, PertenceAoEmitente;

    protected $table = 'importacoes';

    protected $fillable = [
        'conta_id', 'emitente_id', 'user_id', 'arquivo_nome', 'arquivo_caminho', 'arquivo_hash',
        'status', 'total_linhas', 'linhas_invalidas', 'erro', 'reprocessada_de_id',
        'avisos', 'atualizacoes', 'cadastro_aplicado_em', 'total_planilha',
    ];

    protected $casts = ['avisos' => 'array', 'atualizacoes' => 'array', 'cadastro_aplicado_em' => 'datetime'];

    /** Lote de onde este foi reprocessado (null se veio de uma planilha). */
    public function reprocessadaDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reprocessada_de_id');
    }

    /** O lote já foi enviado para emissão? (só então dá para reprocessar) */
    public function foiEnviada(): bool
    {
        return in_array($this->status, ['enviando', 'enviada', 'concluida'], true);
    }

    public function emitente(): BelongsTo
    {
        return $this->belongsTo(Emitente::class);
    }

    public function notas(): HasMany
    {
        return $this->hasMany(NotaFiscal::class);
    }

    public function recontarInvalidas(): void
    {
        $this->update(['linhas_invalidas' => $this->notas()->where('status', StatusNota::Invalida)->count()]);
    }

    /** Situação em linguagem comum: [texto, classes do selo]. */
    public function situacao(?array $resumo = null): array
    {
        $r = $resumo ?? $this->resumo();
        $atencao = $r['invalida'] + $r['rejeitada'] + $r['falhou'];
        $andamento = $r['pendente'] + $r['enviando'] + $r['aguardando_credito'];

        return match (true) {
            $this->status === 'falhou'                      => ['Falhou na leitura', 'bg-warn-100 text-warn-800'],
            in_array($this->status, ['pendente', 'lendo'])  => ['Lendo a planilha', 'bg-info-100 text-info-800'],
            $this->status === 'conferir'                    => ['Aguardando sua conferência', 'bg-amber-100 text-amber-900'],
            $this->status === 'enviando' || $andamento > 0  => ['Em andamento', 'bg-info-100 text-info-800'],
            $atencao > 0                                    => ["Resolver {$atencao}", 'bg-warn-100 text-warn-800'],
            default                                         => ['Concluída', 'bg-ok-100 text-ok-800'],
        };
    }

    /** Contagem por status, para a barra de progresso. */
    public function resumo(): array
    {
        $contagem = $this->notas()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(StatusNota::cases())
            ->mapWithKeys(fn ($s) => [$s->value => (int) ($contagem[$s->value] ?? 0)])
            ->all();
    }
}
