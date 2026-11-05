<?php

return [
    // Créditos de teste ao criar a conta (validade 30 dias). 0 = nenhum.
    'bonus_cadastro' => (int) env('SAAS_BONUS_CADASTRO', 10),

    // Tempo para pagar o Pix antes de expirar
    'pix_validade_minutos' => (int) env('SAAS_PIX_VALIDADE_MIN', 30),

    // Driver de pagamento (Contracts\GatewayPagamento): inter | asaas
    'nome_produto' => env('SAAS_NOME_PRODUTO', 'Emissor NFS-e'),

    'gateway' => env('SAAS_GATEWAY', 'inter'),
];
