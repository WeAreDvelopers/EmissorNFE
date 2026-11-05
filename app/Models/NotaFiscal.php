<?php

namespace App\Models;

use App\Enums\StatusNota;
use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class NotaFiscal extends Model
{
    use PertenceAConta;

    protected $table = 'notas_fiscais';

    protected $fillable = [
        'conta_id', 'emitente_id', 'importacao_id', 'origem', 'linha_planilha', 'status',
        'ciclo_credito', 'serie_dps', 'numero_dps',
        'tomador_documento', 'tomador_nome', 'tomador_email',
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
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(Importacao::class);
    }

    public function emitente(): BelongsTo
    {
        return $this->belongsTo(Emitente::class);
    }

    /**
     * Reserva o próximo número de DPS do EMITENTE + série, com lock.
     * O número é atribuído uma vez só: em reenvio, reaproveita o mesmo (E0014).
     */
    public function reservarNumeroDps(): int
    {
        if ($this->numero_dps) {
            return $this->numero_dps;
        }

        $chave = "dps:{$this->emitente_id}:{$this->serie_dps}";

        return Cache::lock($chave, 10)->block(5, function () {
            $ultimo = static::semIsolamento()
                ->where('emitente_id', $this->emitente_id)
                ->where('serie_dps', $this->serie_dps)
                ->max('numero_dps');

            $this->numero_dps = ((int) $ultimo) + 1;
            $this->save();

            return $this->numero_dps;
        });
    }
}
