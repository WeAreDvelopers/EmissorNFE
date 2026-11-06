<?php

namespace App\Models;

use App\Enums\StatusNota;
use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaFiscal extends Model
{
    use PertenceAConta;

    protected $table = 'notas_fiscais';

    protected $fillable = [
        'conta_id', 'emitente_id', 'importacao_id', 'origem', 'linha_planilha', 'status',
        'ciclo_credito', 'serie_dps', 'numero_dps',
        'tomador_id', 'duplicidade_de_id', 'duplicidade_aprovada', 'tomador_documento', 'tomador_nome', 'tomador_email', 'sem_tomador',
        'codigo_servico', 'codigo_nbs', 'descricao', 'valor', 'data_competencia',
        'chave_acesso', 'numero_nfse', 'xml_dps', 'xml_nfse', 'erros',
        'tentativas', 'emitida_em',
    ];

    protected $casts = [
        'status'           => StatusNota::class,
        'valor'            => 'decimal:2',
        'data_competencia' => 'date',
        'erros'            => 'array',
        'emitida_em'       => 'datetime',
        'email_enviado_em' => 'datetime',
        'sem_tomador'      => 'boolean',
        'duplicidade_aprovada' => 'boolean',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(Importacao::class);
    }

    /** Nota que esta linha parece repetir (só um alerta; ver DetectorDuplicidade). */
    public function duplicadaDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicidade_de_id');
    }

    /** Linhas que parecem repetidas e o usuário ainda não decidiu. */
    public function scopeComDuplicidadePendente($q)
    {
        return $q->whereNotNull('duplicidade_de_id')->where('duplicidade_aprovada', false)->where('status', StatusNota::Rascunho);
    }

    public function tomador(): BelongsTo
    {
        return $this->belongsTo(Tomador::class);
    }

    /** Nome para exibir: nota sem tomador identificado não tem nome de cliente. */
    public function getTomadorRotuloAttribute(): string
    {
        return $this->sem_tomador ? 'Sem destinatário informado' : (string) $this->tomador_nome;
    }

    public function emitente(): BelongsTo
    {
        return $this->belongsTo(Emitente::class);
    }

    /**
     * Reserva o número da DPS (empresa + série + ambiente), com trava no banco.
     * O número é atribuído uma vez só: em reenvio, reaproveita o mesmo (E0014).
     */
    public function reservarNumeroDps(): int
    {
        return app(\App\Services\Nfse\NumeracaoDps::class)->reservar($this);
    }
}
