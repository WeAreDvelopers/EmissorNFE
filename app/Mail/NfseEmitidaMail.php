<?php

namespace App\Mail;

use App\Models\NotaFiscal;
use App\Services\Nfse\Danfse;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Envia ao cliente (tomador) a NFS-e emitida: DANFSE em PDF e o XML anexos.
 *
 * Vai para a fila guardando só os ids: o worker não tem "conta ativa", então a nota é carregada
 * dentro do contexto da conta (o isolamento por conta continua valendo).
 */
class NfseEmitidaMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $notaId;
    public int $contaId;

    public function __construct(NotaFiscal $nota)
    {
        $this->notaId = $nota->id;
        $this->contaId = $nota->conta_id;
    }

    /** A nota, sempre fresca e dentro da conta dela. */
    public function nota(): NotaFiscal
    {
        return app(ContaAtual::class)->como(
            $this->contaId,
            fn () => NotaFiscal::with('emitente')->findOrFail($this->notaId)
        );
    }

    private function homologacao(NotaFiscal $nota): bool
    {
        return (int) $nota->emitente?->ambiente !== 1;
    }

    public function envelope(): Envelope
    {
        $nota = $this->nota();
        $empresa = $nota->emitente?->razao_social ?? 'Prestador';

        return new Envelope(subject: ($this->homologacao($nota) ? '[TESTE] ' : '')."NFS-e nº {$nota->numero_nfse} de {$empresa}");
    }

    public function content(): Content
    {
        $nota = $this->nota();

        return new Content(view: 'emails.nfse-emitida', with: [
            'nota' => $nota, 'empresa' => $nota->emitente, 'teste' => $this->homologacao($nota),
        ]);
    }

    /** @return Attachment[] */
    public function attachments(): array
    {
        $nota = $this->nota();
        $danfse = app(Danfse::class);

        return [
            Attachment::fromData(fn () => $danfse->pdf($nota), $danfse->nomeArquivo($nota, 'pdf'))->withMime('application/pdf'),
            Attachment::fromData(fn () => $nota->xml_nfse, $danfse->nomeArquivo($nota, 'xml'))->withMime('application/xml'),
        ];
    }
}
