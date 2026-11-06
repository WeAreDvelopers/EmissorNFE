@props(['nota'])
@php
    $emails = \App\Http\Controllers\NotaDocumentoController::destinatarios($nota);
    $rotuloEnvio = $emails ? 'Enviar para '.\Illuminate\Support\Str::limit($emails[0], 28).(count($emails) > 1 ? ' +'.(count($emails) - 1) : '').'?' : '';
@endphp
{{-- Documentos da NFS-e emitida: PDF (DANFSE), XML, chave de acesso e envio por e-mail ao cliente --}}
<div class="flex flex-wrap sm:justify-end gap-1.5 items-center">
    <a href="{{ route('notas.danfse', $nota) }}" target="_blank" rel="noopener" class="btn-acao" title="Abrir o DANFSe (PDF) em outra aba">PDF</a>
    <a href="{{ route('notas.xml', $nota) }}" class="btn-acao" title="Baixar o XML da NFS-e">XML</a>
    <button type="button" class="btn-acao" data-copiar="{{ $nota->chave_acesso }}" data-aviso="Chave de acesso copiada" title="Copiar a chave de acesso">Chave</button>
    @can('emitir')
        <button type="button" class="btn-acao"
                @if ($emails)
                    data-acao-url="{{ route('notas.email', $nota) }}" data-metodo="POST" data-confirmar="{{ $rotuloEnvio }}" data-ajax-texto="Enviando…"
                    title="Enviar o PDF e o XML por e-mail ao cliente"
                @else
                    disabled title="O cliente desta nota não tem um e-mail válido"
                @endif>E-mail</button>
    @endcan
</div>
@if ($nota->email_enviado_em)
    <div class="text-xs text-ink-600 mt-1 sm:text-right">E-mail enviado em {{ $nota->email_enviado_em->timezone('America/Sao_Paulo')->format('d/m H:i') }}</div>
@endif
