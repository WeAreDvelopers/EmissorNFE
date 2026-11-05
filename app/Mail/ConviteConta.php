<?php

namespace App\Mail;

use App\Models\Convite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ConviteConta extends Mailable
{
    use Queueable;

    public function __construct(public Convite $convite) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Você foi convidado para emitir notas fiscais');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.convite',
            with: ['url' => route('convites.aceitar', $this->convite->token)],
        );
    }
}
