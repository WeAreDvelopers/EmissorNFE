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

    // TSVerAplic: 1 a 20 caracteres. Um valor maior no .env derrubaria toda emissão no XSD, então corta em 20.
    'versao_app' => substr(trim((string) env('NFSE_VERSAO_APP', 'NFSe_Flipy_0.0.1')) ?: 'NFSe_Flipy_0.0.1', 0, 20),
    'versao_dps' => env('NFSE_VERSAO_DPS', '1.01'),

    // Leiaute da DPS por ambiente. A restrita (2) já usa o de 20/10/2026; a produção (1) ainda usa o de 09/02/2026
    // (sem finNFSe, xDescServ antes de cNBS). Quando a produção migrar: NFSE_LEIAUTE_NOVO_PRODUCAO=true.
    'leiaute_novo' => [
        1 => (bool) env('NFSE_LEIAUTE_NOVO_PRODUCAO', false),
        2 => true,
    ],

    // XSD oficial v1.01 de 20/10/2026 (leiaute novo) e o anterior, de 09/02/2026 (produção) — ver resources/xsd/LEIA-ME.md
    'xsd_dps' => resource_path('xsd/DPS_v1.01.xsd'),
    'xsd_dps_producao' => resource_path('xsd/producao/DPS_v1.01.xsd'),

    // XSD do pedido de registro de evento (cancelamento)
    'xsd_evento' => resource_path('xsd/pedRegEvento_v1.01.xsd'),

    // Fila dedicada para chamadas à SEFIN (controle de vazão separado)
    'fila_emissao' => env('NFSE_FILA', 'nfse'),

    // Máximo de envios por minuto à SEFIN, por emitente
    // Máximo de linhas por planilha importada (notas e tomadores) e por lote editado em tabela
    'limite_linhas_importacao' => (int) env('NFSE_LIMITE_LINHAS_IMPORTACAO', 1000),

    'limite_por_minuto' => (int) env('NFSE_LIMITE_MINUTO', 60),
];
