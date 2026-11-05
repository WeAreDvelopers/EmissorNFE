<?php

namespace App\Services\Pagamentos;

use Carbon\CarbonInterface;

final class CobrancaPix
{
    public function __construct(
        public readonly string $gatewayId,
        public readonly string $copiaCola,
        public readonly ?string $qrBase64,
        public readonly ?CarbonInterface $expiraEm,
    ) {}
}
