<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Mail\NfseEmitidaMail;
use App\Models\NotaFiscal;
use App\Services\Nfse\Danfse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Documentos de uma NFS-e emitida: XML, DANFSE (PDF) e envio por e-mail ao cliente. */
class NotaDocumentoController extends Controller
{
    private function emitida(NotaFiscal $nota): NotaFiscal
    {
        abort_unless($nota->status === StatusNota::Emitida && $nota->xml_nfse, 404, 'Esta nota ainda não foi emitida.');

        return $nota;
    }

    public function xml(NotaFiscal $nota, Danfse $danfse)
    {
        $this->authorize('consultar');
        $this->emitida($nota);

        return response($nota->xml_nfse, 200, [
            'Content-Type'        => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$danfse->nomeArquivo($nota, 'xml').'"',
        ]);
    }

    public function danfse(Request $request, NotaFiscal $nota, Danfse $danfse)
    {
        $this->authorize('consultar');
        $this->emitida($nota);

        return response($danfse->pdf($nota), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => ($request->boolean('baixar') ? 'attachment' : 'inline').'; filename="'.$danfse->nomeArquivo($nota, 'pdf').'"',
        ]);
    }

    /** Endereços válidos do cliente (a planilha pode trazer vários, separados por vírgula ou ponto e vírgula). */
    public static function destinatarios(NotaFiscal $nota): array
    {
        // Cliente cadastrado com vários e-mails: a nota leva o principal, mas o aviso vai para todos
        // (a menos que o e-mail da nota tenha sido corrigido à mão, aí vale o da nota).
        $cadastro = $nota->tomador?->emails ?? [];
        $lista = $cadastro && ($cadastro[0] ?? null) === $nota->tomador_email ? $cadastro : preg_split('/[;,\s]+/', (string) $nota->tomador_email, -1, PREG_SPLIT_NO_EMPTY);

        return collect($lista)
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique(fn ($e) => strtolower($e))->take(5)->values()->all();
    }

    public function email(Request $request, NotaFiscal $nota)
    {
        $this->authorize('emitir');
        $this->emitida($nota);

        $destinos = self::destinatarios($nota);
        abort_if($destinos === [], 422, 'Esta nota não tem um e-mail válido do cliente. Corrija o e-mail na linha e tente de novo.');

        Mail::to($destinos)->queue(new NfseEmitidaMail($nota));
        $nota->forceFill(['email_enviado_em' => now()])->save();

        $msg = 'E-mail com o PDF e o XML enviado para '.implode(', ', $destinos).'.';

        return $request->expectsJson() ? response()->json(['ok' => true, 'mensagem' => $msg]) : back()->with('ok', $msg);
    }
}
