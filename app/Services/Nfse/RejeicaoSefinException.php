<?php

namespace App\Services\Nfse;

use Exception;

/** A SEFIN recusou a DPS por regra de negócio (dados inválidos). Não adianta reenviar igual. */
class RejeicaoSefinException extends Exception
{
    public function __construct(public array $erros)
    {
        parent::__construct('DPS rejeitada pela SEFIN: '.json_encode($erros, JSON_UNESCAPED_UNICODE));
    }
}
