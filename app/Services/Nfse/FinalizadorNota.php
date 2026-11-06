<?php

namespace App\Services\Nfse;

use App\Models\NotaFiscal;
use App\Services\Creditos\CarteiraService;

/** Fecha uma nota como autorizada (emissão normal ou recuperada pela conciliação) e consome o crédito. */
class FinalizadorNota
{
    public function __construct(private CarteiraService $creditos) {}

    /**
     * @param bool $cobrarSeNecessario  nota que já tinha o crédito devolvido (falha definitiva) mas a SEFIN autorizou:
     *                                  tenta reservar de novo antes de consumir (sem saldo, fica sem cobrança)
     */
    public function emitida(NotaFiscal $nota, array $retorno, bool $cobrarSeNecessario = false): void
    {
        $nota->update([
            'status'       => \App\Enums\StatusNota::Emitida,
            'chave_acesso' => $retorno['chave_acesso'],
            'numero_nfse'  => $retorno['numero_nfse'] ?? $nota->numero_nfse,
            'xml_nfse'     => $retorno['xml_nfse'] ?? $nota->xml_nfse,
            'erros'        => null,
            'emitida_em'   => $nota->emitida_em ?? now(),
        ]);

        if ($cobrarSeNecessario) {
            $this->creditos->reservar($nota);   // idempotente: se a reserva já existe, não cobra duas vezes
        }
        $this->creditos->consumir($nota);
    }
}
