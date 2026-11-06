<?php

namespace App\Services\Nfse;

use App\Models\Auditoria;
use App\Models\NotaFiscal;
use App\Services\Creditos\CarteiraService;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $this->enviarEmailAutomatico($nota);
    }

    /**
     * Empresa com "enviar ao cliente ao emitir" ligado: manda o PDF e o XML por e-mail.
     * Só em produção (em teste o envio é manual) e nunca derruba a emissão se o e-mail falhar.
     */
    private function enviarEmailAutomatico(NotaFiscal $nota): void
    {
        try {
            app(ContaAtual::class)->como($nota->conta_id, function () use ($nota) {
                $nota = NotaFiscal::with(['emitente', 'tomador'])->find($nota->id);
                $emitente = $nota?->emitente;

                if (! $nota || ! $emitente?->enviar_email_automatico || $emitente->ehHomologacao() || ! $nota->xml_nfse || $nota->email_enviado_em) {
                    return;
                }

                $destinos = \App\Http\Controllers\NotaDocumentoController::destinatarios($nota);
                if ($destinos === []) {
                    return;
                }

                Mail::to($destinos)->queue(new \App\Mail\NfseEmitidaMail($nota));
                $nota->forceFill(['email_enviado_em' => now()])->save();
                Auditoria::registrar('email_enviado', "NFS-e nº {$nota->numero_nfse} enviada por e-mail (automático).", ['nota' => $nota->id, 'destinatarios' => $destinos, 'automatico' => true], $nota->conta_id);
            });
        } catch (\Throwable $e) {
            Log::warning('E-mail automático da NFS-e falhou', ['nota' => $nota->id, 'erro' => $e->getMessage()]);
        }
    }
}
