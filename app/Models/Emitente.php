<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um CNPJ que emite notas, com seu certificado A1. Uma conta pode ter vários. */
class Emitente extends Model
{
    use PertenceAConta;

    protected $fillable = [
        'conta_id', 'cnpj', 'razao_social', 'inscricao_municipal', 'codigo_municipio', 'codigo_servico_padrao',
        'op_simples_nacional', 'regime_apuracao_sn', 'regime_especial', 'aliquota_simples',
        'tributos_aprox', 'serie_dps', 'ambiente', 'certificado_arquivo', 'certificado_senha',
        'certificado_validade', 'ativo',
    ];

    protected $casts = [
        'tributos_aprox'       => 'array',
        'certificado_senha'    => 'encrypted',
        'certificado_validade' => 'datetime',
        'ativo'                => 'boolean',
    ];

    protected $hidden = ['certificado_senha', 'certificado_arquivo'];

    public function notas(): HasMany
    {
        return $this->hasMany(NotaFiscal::class);
    }

    /** Mesmo formato que o DpsBuilder lia de config('nfse.prestador'). */
    public function paraDps(): array
    {
        return [
            'cnpj'                => $this->cnpj,
            'inscricao_municipal' => $this->inscricao_municipal,
            'codigo_municipio'    => $this->codigo_municipio,
            'op_simples_nacional' => $this->op_simples_nacional,
            'regime_apuracao_sn'  => $this->regime_apuracao_sn ?? 1,
            'regime_especial'     => $this->regime_especial,
            'aliquota_simples'    => $this->aliquota_simples,
            'tributos_aprox'      => $this->tributos_aprox ?? ['federal' => null, 'estadual' => null, 'municipal' => null],
        ];
    }

    public function certificadoVenceEmDias(): ?int
    {
        return $this->certificado_validade ? (int) now()->diffInDays($this->certificado_validade, false) : null;
    }
}
