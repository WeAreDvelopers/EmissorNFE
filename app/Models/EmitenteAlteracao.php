<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha do histórico de uma empresa (o primeiro cadastro e cada alteração). Só se acrescenta; nunca se altera. Nunca guarda senha nem o arquivo do certificado. */
class EmitenteAlteracao extends Model
{
    use PertenceAConta;

    protected $table = 'emitente_alteracoes';

    public const UPDATED_AT = null;

    protected $fillable = ['conta_id', 'emitente_id', 'user_id', 'origem', 'campos'];

    protected $casts = ['campos' => 'array'];

    /** Nome de cada campo para a tela. */
    public const CAMPOS = [
        'razao_social' => 'Razão social', 'cnpj' => 'CNPJ', 'inscricao_municipal' => 'Inscrição municipal', 'codigo_municipio' => 'Município (código IBGE)',
        'codigo_servico_padrao' => 'Código de serviço padrão', 'descricao_servico_padrao' => 'Descrição padrão do serviço', 'codigo_nbs_padrao' => 'NBS padrão',
        'codigo_tributacao_municipal' => 'Código complementar municipal', 'op_simples_nacional' => 'Situação no Simples Nacional', 'regime_apuracao_sn' => 'Regime de apuração (Simples)',
        'regime_especial' => 'Regime especial de tributação', 'modo_tributos_aprox' => 'Valor aproximado dos tributos (forma)', 'aliquota_simples' => 'Alíquota do Simples (%)',
        'tributos_aprox' => 'Tributos aproximados (%)', 'pis_cofins_cst' => 'CST PIS/COFINS', 'tp_ret_pis_cofins' => 'Retenção PIS/COFINS', 'aliquota_pis' => 'Alíquota PIS (%)',
        'aliquota_cofins' => 'Alíquota COFINS (%)', 'trib_issqn' => 'Tributação do ISSQN', 'tp_imunidade' => 'Tipo de imunidade', 'retencao_issqn' => 'Retenção do ISSQN',
        'deducao_tipo' => 'Dedução/redução', 'deducao_valor' => 'Valor da dedução/redução', 'codigo_municipio_prestacao' => 'Município da prestação (código IBGE)',
        'local_prestacao' => 'Local da prestação', 'informar_serie_dps' => 'Informar série e número da DPS', 'serie_dps' => 'Série', 'ambiente' => 'Ambiente', 'enviar_email_automatico' => 'E-mail automático ao cliente', 'email_copia' => 'E-mail em cópia',
        'certificado' => 'Certificado digital',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
