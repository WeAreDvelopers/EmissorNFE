<?php

namespace App\Support;

/** Tabelas de códigos da DPS (XSD v1.01 de 20/10/2026) usadas na emissão de nota completa personalizada. */
class TabelasDps
{
    /** Tributação do ISSQN. A exportação (3) não entra: exige tomador no exterior, que o sistema ainda não emite. */
    public const TRIBUTACAO_ISSQN = [1 => 'Operação tributável', 2 => 'Imunidade', 4 => 'Não incidência'];

    /** Tipo de imunidade (o 0, "não informado", é recusado pelos emissores nacionais: E0593). */
    public const IMUNIDADES = [
        1 => 'Patrimônio, renda ou serviços, uns dos outros (CF88, art. 150, VI, a)',
        2 => 'Templos de qualquer culto (CF88, art. 150, VI, b)',
        3 => 'Partidos políticos, sindicatos de trabalhadores, instituições de educação e de assistência social sem fins lucrativos (CF88, art. 150, VI, c)',
        4 => 'Livros, jornais, periódicos e o papel para sua impressão (CF88, art. 150, VI, d)',
        5 => 'Fonogramas e videofonogramas musicais produzidos no Brasil (CF88, art. 150, VI, e)',
    ];

    /** Situação tributária do PIS/COFINS (CST). */
    public const CST_PIS_COFINS = [
        '00' => 'Nenhum',
        '01' => 'Operação tributável com alíquota básica',
        '02' => 'Operação tributável com alíquota diferenciada',
        '03' => 'Operação tributável com alíquota por unidade de medida de produto',
        '04' => 'Operação tributável monofásica - revenda a alíquota zero',
        '05' => 'Operação tributável por substituição tributária',
        '06' => 'Operação tributável a alíquota zero',
        '07' => 'Operação isenta da contribuição',
        '08' => 'Operação sem incidência da contribuição',
        '09' => 'Operação com suspensão da contribuição',
        '49' => 'Outras operações de saída',
        '50' => 'Direito a crédito - receita tributada no mercado interno',
        '51' => 'Direito a crédito - receita não tributada no mercado interno',
        '52' => 'Direito a crédito - receita de exportação',
        '53' => 'Direito a crédito - receitas tributadas e não tributadas no mercado interno',
        '54' => 'Direito a crédito - receitas tributadas no mercado interno e de exportação',
        '55' => 'Direito a crédito - receitas não tributadas no mercado interno e de exportação',
        '56' => 'Direito a crédito - receitas tributadas, não tributadas e de exportação',
        '60' => 'Crédito presumido - receita tributada no mercado interno',
        '61' => 'Crédito presumido - receita não tributada no mercado interno',
        '62' => 'Crédito presumido - receita de exportação',
        '63' => 'Crédito presumido - receitas tributadas e não tributadas no mercado interno',
        '64' => 'Crédito presumido - receitas tributadas no mercado interno e de exportação',
        '65' => 'Crédito presumido - receitas não tributadas no mercado interno e de exportação',
        '66' => 'Crédito presumido - receitas tributadas, não tributadas e de exportação',
        '67' => 'Crédito presumido - outras operações',
        '70' => 'Aquisição sem direito a crédito',
        '71' => 'Aquisição com isenção',
        '72' => 'Aquisição com suspensão',
        '73' => 'Aquisição a alíquota zero',
        '74' => 'Aquisição sem incidência da contribuição',
        '75' => 'Aquisição por substituição tributária',
        '98' => 'Outras operações de entrada',
        '99' => 'Outras operações',
    ];

    /** Tipo de retenção do PIS/COFINS/CSLL. */
    public const RETENCAO_PIS_COFINS = [
        0 => 'PIS/COFINS/CSLL não retidos',
        1 => 'PIS/COFINS retidos',
        2 => 'PIS/COFINS não retidos',
        3 => 'PIS/COFINS/CSLL retidos',
        4 => 'PIS/COFINS retidos, CSLL não retido',
        5 => 'PIS retido, COFINS/CSLL não retidos',
        6 => 'COFINS retido, PIS/CSLL não retidos',
        7 => 'PIS não retido, COFINS/CSLL retidos',
        8 => 'PIS/COFINS não retidos, CSLL retido',
        9 => 'COFINS não retido, PIS/CSLL retidos',
    ];

    /** Retenções em que a CSLL retida é obrigatória (E0724) e as em que é proibida (E0720). */
    public const CSLL_PROIBIDA = [0];
    public const CSLL_OPCIONAL = [2];

    public static function csllObrigatoria(int $retencao): bool
    {
        return ! in_array($retencao, [...self::CSLL_PROIBIDA, ...self::CSLL_OPCIONAL], true);
    }
}
