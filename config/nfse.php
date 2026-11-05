<?php

/**
 * Configuração técnica da NFS-e.
 * Dados do prestador, certificado, série e ambiente agora são POR EMITENTE (tabela emitentes).
 */
return [

    // 1 = Produção, 2 = Produção Restrita (homologação) — o emitente escolhe o seu
    'urls' => [
        1 => ['sefin' => 'https://sefin.nfse.gov.br/SefinNacional'],
        2 => ['sefin' => 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional'],
    ],

    'versao_app' => env('NFSE_VERSAO_APP', 'MeuEmissor-1.0'),
    'versao_dps' => env('NFSE_VERSAO_DPS', '1.01'),

    // XSD oficial v1.01 (com ajuste local do pattern da série — ver resources/xsd/LEIA-ME.md)
    'xsd_dps' => resource_path('xsd/DPS_v1.01.xsd'),

    // Fila dedicada para chamadas à SEFIN (controle de vazão separado)
    'fila_emissao' => env('NFSE_FILA', 'nfse'),

    // Máximo de envios por minuto à SEFIN, por emitente
    'limite_por_minuto' => (int) env('NFSE_LIMITE_MINUTO', 60),
];
