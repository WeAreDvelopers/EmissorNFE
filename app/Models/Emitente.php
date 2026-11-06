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
        'conta_id', 'cnpj', 'razao_social', 'inscricao_municipal', 'codigo_municipio', 'codigo_servico_padrao', 'descricao_servico_padrao', 'codigo_nbs_padrao',
        'op_simples_nacional', 'regime_apuracao_sn', 'regime_especial', 'aliquota_simples',
        'tributos_aprox', 'serie_dps', 'ambiente', 'certificado_arquivo', 'certificado_senha',
        'certificado_validade', 'logo_arquivo', 'ativo',
    ];

    protected $casts = [
        'tributos_aprox'       => 'array',
        'certificado_senha'    => 'encrypted',
        'certificado_validade' => 'datetime',
        'ativo'                => 'boolean',
        'cobranca_desligada'   => 'boolean',
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

    /** URL pública da logo (pasta storage/app/public/logos, exposta por public/storage). */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_arquivo ? asset('storage/'.$this->logo_arquivo) : null;
    }

    /** Ambiente de teste (homologação): não gasta crédito e a nota não tem valor fiscal. */
    public function ehHomologacao(): bool
    {
        return (int) $this->ambiente !== 1;
    }

    /** Notas desta empresa não consomem créditos: ambiente de teste ou cobrança desligada pelo master. */
    public function semCobranca(): bool
    {
        return $this->ehHomologacao() || $this->cobranca_desligada;
    }

    public function certificadoVenceEmDias(): ?int
    {
        return $this->certificado_validade ? (int) now()->diffInDays($this->certificado_validade, false) : null;
    }
}
