<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use App\Support\ListaServicoNacional;
use App\Support\MunicipiosIbge;
use App\Support\TabelasDps;
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
        'certificado_validade', 'logo_arquivo', 'ativo', 'enviar_email_automatico', 'email_copia', 'codigo_tributacao_municipal',
        'modo_tributos_aprox', 'pis_cofins_cst', 'tp_ret_pis_cofins', 'aliquota_pis', 'aliquota_cofins',
        'trib_issqn', 'tp_imunidade', 'retencao_issqn', 'deducao_tipo', 'deducao_valor', 'codigo_municipio_prestacao',
        'local_prestacao', 'informar_serie_dps',
    ];

    protected $casts = [
        'tributos_aprox'       => 'array',
        'certificado_senha'    => 'encrypted',
        'certificado_validade' => 'datetime',
        'ativo'                => 'boolean',
        'informar_serie_dps'   => 'boolean',
        'enviar_email_automatico' => 'boolean',
        'cobranca_desligada'   => 'boolean',
    ];

    protected $hidden = ['certificado_senha', 'certificado_arquivo'];

    public function alteracoes(): HasMany
    {
        return $this->hasMany(EmitenteAlteracao::class)->latest('created_at')->latest('id');
    }

    public function notas(): HasMany
    {
        return $this->hasMany(NotaFiscal::class);
    }

    public function codigosMunicipais(): HasMany
    {
        return $this->hasMany(CodigoMunicipal::class)->orderBy('codigo');
    }

    /** Códigos complementares municipais cadastrados, no formato que as telas usam (lista suspensa). */
    public function listaCodigosMunicipais(): array
    {
        return $this->exists
            ? $this->codigosMunicipais->map(fn ($c) => ['codigo' => $c->codigo, 'descricao' => (string) $c->descricao, 'servico' => (string) $c->codigo_servico])->values()->all()
            : [];
    }

    /** Mesmo formato que o DpsBuilder lia de config('nfse.prestador'). */
    public function paraDps(): array
    {
        return [
            'cnpj'                => $this->cnpj,
            'codigo_tributacao_municipal' => $this->codigo_tributacao_municipal,
            'inscricao_municipal' => $this->inscricao_municipal,
            'codigo_municipio'    => $this->codigo_municipio,
            'op_simples_nacional' => $this->op_simples_nacional,
            'regime_apuracao_sn'  => $this->regime_apuracao_sn ?? 1,
            'regime_especial'     => $this->regime_especial,
            'aliquota_simples'    => $this->aliquota_simples,
            'tributos_aprox'      => $this->tributos_aprox ?? ['federal' => null, 'estadual' => null, 'municipal' => null],
            'modo_tributos_aprox' => $this->modoTributosAprox(),
            'trib_issqn'          => (int) ($this->trib_issqn ?: 1),
            'tp_imunidade'        => $this->tp_imunidade ? (int) $this->tp_imunidade : null,
            'retencao_issqn'      => (int) ($this->retencao_issqn ?: 1),
            'deducao_tipo'        => $this->deducao_tipo ?: null,
            'deducao_valor'       => $this->deducao_valor !== null ? (float) $this->deducao_valor : null,
            'codigo_municipio_prestacao' => $this->local_prestacao === 'outro' ? ($this->codigo_municipio_prestacao ?: null) : null,
            'local_prestacao'     => $this->local_prestacao ?: 'emitente',
            'pis_cofins_cst'      => $this->pis_cofins_cst ?: '00',
            'tp_ret_pis_cofins'   => (int) $this->tp_ret_pis_cofins,
            'aliquota_pis'        => $this->aliquota_pis,
            'aliquota_cofins'     => $this->aliquota_cofins,
        ];
    }

    /** Município da prestação do serviço: o da empresa, ou outro quando a empresa presta sempre em outro lugar. */
    public function codigoMunicipioPrestacao(): string
    {
        return (string) ($this->local_prestacao === 'outro' && $this->codigo_municipio_prestacao ? $this->codigo_municipio_prestacao : $this->codigo_municipio);
    }

    /** Dedução/redução da base de cálculo: não optante, ou ME/EPP com o ISSQN apurado pela NFS-e (E0436, E0441, E0442). */
    public function deducaoPermitida(): bool
    {
        $op = (int) $this->op_simples_nacional;

        return $op === 1 || ($op === 3 && (int) $this->regime_apuracao_sn === 2);
    }

    public const MODOS_TRIBUTOS_APROX = [
        'monetario'  => 'Preencher os valores monetários em cada NFS-e emitida',
        'percentual' => 'Configurar os valores percentuais correspondentes',
        'simples'    => 'Informar alíquota do Simples Nacional',
        'nenhum'     => 'Não informar (MEI)',
    ];

    /** Formas de informar o valor aproximado dos tributos que a SEFIN aceita em cada regime (E0710, E0712, E0713). */
    public static function modosPermitidos(int $op): array
    {
        return match ($op) {
            1 => ['percentual', 'monetario'],
            3 => ['simples', 'percentual', 'monetario'],
            default => ['nenhum', 'percentual', 'monetario'],   // MEI
        };
    }

    /** Forma escolhida na empresa; sem escolha (ou fora do que o regime aceita) vale o padrão do regime. */
    public function modoTributosAprox(): string
    {
        $op = (int) $this->op_simples_nacional;
        $permitidos = self::modosPermitidos($op);

        return in_array($this->modo_tributos_aprox, $permitidos, true) ? $this->modo_tributos_aprox : $permitidos[0];
    }

    public const REGIMES_ESPECIAIS = [0 => 'Nenhum', 1 => 'Ato cooperado', 2 => 'Estimativa', 3 => 'Sociedade de profissionais', 4 => 'Cooperativa', 5 => 'MEI', 6 => 'ME/EPP', 9 => 'Outros'];

    /**
     * Todos os campos das telas do Emissor Nacional (Serviço, Tributação Municipal, Federal, IBS/CBS, Valor aproximado)
     * e como cada um sai nas notas desta empresa. É a fonte única do resumo "Como a nota sai" (cadastro da empresa
     * e emissão manual). Origem de cada linha:
     *  - cadastro:  vem do cadastro da empresa (editável na tela da empresa);
     *  - fixo:      valor que o sistema sempre usa (país, município de incidência);
     *  - bloqueado: o Emissor Nacional não deixa preencher;
     *  - por_nota:  vem com este valor, mas pode ser alterado em cada nota (emissão manual, "Nota fiscal completa personalizada").
     *
     * @return array<string, list<array{rotulo:string, valor:string, origem:string}>>
     */
    public function camposFiscais(): array
    {
        $op = (int) $this->op_simples_nacional;
        $pct = fn ($v) => $v === null || $v === '' ? 'não informado' : number_format((float) $v, 2, ',', '.').'%';
        $cod = (string) $this->codigo_servico_padrao;
        $cidade = $this->codigo_municipio ? (MunicipiosIbge::rotulo($this->codigo_municipio) ?: 'código IBGE '.$this->codigo_municipio) : 'não informado';
        $codPrest = $this->codigoMunicipioPrestacao();
        $cidadePrest = $codPrest !== (string) $this->codigo_municipio ? (MunicipiosIbge::rotulo($codPrest) ?: 'código IBGE '.$codPrest) : $cidade;
        $trib = (int) ($this->trib_issqn ?: 1);
        $tribAprox = $this->tributos_aprox ?? [];
        $imune = $trib === 2 && $this->tp_imunidade ? ' · '.(TabelasDps::IMUNIDADES[(int) $this->tp_imunidade] ?? '') : '';
        $deducao = match ($this->deducao_tipo) {
            'percentual' => 'Sim, '.number_format((float) $this->deducao_valor, 2, ',', '.').'%',
            'valor'      => 'Sim, R$ '.number_format((float) $this->deducao_valor, 2, ',', '.'),
            default      => 'Não',
        };

        $cst = $this->pis_cofins_cst ?: '00';
        $federal = match ($op) {
            1 => [
                ['Situação tributária do PIS/COFINS', $cst.' - '.(TabelasDps::CST_PIS_COFINS[$cst] ?? ''), 'cadastro'],
                ...($cst !== '00' ? [['Alíquotas do PIS / COFINS', $pct($this->aliquota_pis).' / '.$pct($this->aliquota_cofins), 'cadastro']] : []),
                ['Tipo de retenção do PIS/COFINS/CSLL', (int) $this->tp_ret_pis_cofins.' - '.(TabelasDps::RETENCAO_PIS_COFINS[(int) $this->tp_ret_pis_cofins] ?? ''), 'cadastro'],
                ['IRRF', 'sem preenchimento', 'por_nota'],
                ['Contribuições sociais retidas', 'sem preenchimento', 'por_nota'],
                ['Contribuição previdenciária retida', 'sem preenchimento', 'por_nota'],
            ],
            default => [['Tributação federal (PIS/COFINS, IRRF, contribuições)', $op === 2 ? 'não se aplica ao MEI' : 'não informada: tributos federais já dentro do Simples Nacional', 'fixo']],
        };

        $modo = $this->modoTributosAprox();
        $aproximado = [['Forma de informar', self::MODOS_TRIBUTOS_APROX[$modo], 'cadastro']];
        if ($modo === 'simples') {
            $aproximado[] = ['Alíquota do Simples Nacional', $pct($this->aliquota_simples), 'cadastro'];
        } elseif ($modo !== 'nenhum') {
            $rotulos = $modo === 'monetario' ? ' (%, base para calcular o valor em R$)' : '';
            $aproximado[] = ['Federal'.$rotulos, $pct($tribAprox['federal'] ?? null), 'cadastro'];
            $aproximado[] = ['Estadual', $pct($tribAprox['estadual'] ?? null), 'cadastro'];
            $aproximado[] = ['Municipal', $pct($tribAprox['municipal'] ?? null), 'cadastro'];
        }

        $grupos = [
            'Local e serviço' => [
                ['País', 'Brasil', 'fixo'],
                ['Município da prestação do serviço', $cidadePrest, 'cadastro'],
                ['Código de tributação nacional', $cod !== '' ? preg_replace('/^(\d{2})(\d{2})(\d{2})$/', '$1.$2.$3', $cod).(($d = ListaServicoNacional::descricao($cod)) ? ' · '.$d : '') : 'sem padrão: informe em cada nota', 'cadastro'],
                ['Código complementar municipal', $op === 2 ? 'não se aplica ao MEI' : (filled($this->codigo_tributacao_municipal) ? str_pad($this->codigo_tributacao_municipal, 3, '0', STR_PAD_LEFT) : 'não usado'), 'cadastro'],
                ['Imunidade, exportação ou não incidência do ISSQN?', $trib === 1 ? 'Não' : 'Sim ('.mb_strtolower(TabelasDps::TRIBUTACAO_ISSQN[$trib] ?? '').')', 'cadastro'],
                ['Município de incidência do ISSQN', $trib === 1 ? $cidadePrest.' (o mesmo da prestação)' : 'não informado (sem incidência do ISSQN)', 'fixo'],
                ['Item da NBS', filled($this->codigo_nbs_padrao) ? $this->codigo_nbs_padrao : 'sem padrão (opcional)', 'cadastro'],
                ['Código indicador da operação', 'bloqueado no Emissor Nacional: não é enviado', 'bloqueado'],
                ['Descrição do serviço', filled($this->descricao_servico_padrao) ? \Illuminate\Support\Str::limit(trim($this->descricao_servico_padrao), 90) : 'sem padrão: informe em cada nota', 'cadastro'],
            ],
            'Tributação municipal' => [
                ['Tributação do ISSQN sobre o serviço prestado', (TabelasDps::TRIBUTACAO_ISSQN[$trib] ?? 'Operação tributável').$imune, 'cadastro'],
                ['Regime especial de tributação', self::REGIMES_ESPECIAIS[(int) $this->regime_especial] ?? 'Nenhum', 'cadastro'],
                ['Exigibilidade do ISSQN suspensa?', 'Não', 'bloqueado'],
                ['Retenção do ISSQN pelo tomador ou intermediário?', (int) $this->retencao_issqn === 2 ? 'Sim, retido pelo tomador' : 'Não', 'cadastro'],
                ['Serviço amparado por benefício municipal?', 'Não', 'bloqueado'],
                ['Dedução/redução da base de cálculo do ISSQN?', $deducao, 'cadastro'],
            ],
            'Tributação federal' => $federal,
            'IBS/CBS' => [
                ['Código de situação tributária', 'não informado (bloqueado no Emissor Nacional)', 'bloqueado'],
                ['Código de classificação tributária', 'não informado (bloqueado no Emissor Nacional)', 'bloqueado'],
            ],
            'Valor aproximado dos tributos' => $aproximado,
            'Numeração da nota' => [
                ['Série da DPS', (string) ($this->serie_dps ?: '00001'), 'cadastro'],
                ['Ambiente', (int) $this->ambiente === 1 ? 'Produção' : 'Teste (homologação)', 'cadastro'],
                ['Número da DPS e da NFS-e', 'sequência automática por série e ambiente (veja a numeração)', 'cadastro'],
            ],
        ];

        return array_map(fn ($linhas) => array_map(fn ($l) => ['rotulo' => $l[0], 'valor' => $l[1], 'origem' => $l[2]], $linhas), $grupos);
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
