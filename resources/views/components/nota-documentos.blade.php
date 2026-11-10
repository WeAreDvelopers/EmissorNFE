@props(['nota'])
@php
    $emails = \App\Http\Controllers\NotaDocumentoController::destinatarios($nota);
    $rotuloEnvio = $emails ? 'Enviar para '.\Illuminate\Support\Str::limit($emails[0], 28).(count($emails) > 1 ? ' +'.(count($emails) - 1) : '').'?' : '';
    $item = 'flex w-full items-center gap-2.5 px-3.5 min-h-[40px] text-left text-sm text-ink-900 no-underline hover:bg-gray-50 focus:bg-gray-50 focus:outline-none';
@endphp
{{-- Documentos da NFS-e emitida: PDF em destaque e o resto (XML, chave, e-mail, cancelar) no menu "⋯".
     O menu usa posição fixa para não ser cortado pela borda da tabela e abre para cima quando está no fim da tela. --}}
<div class="flex justify-end gap-1.5 items-center"
     x-data="{ aberto: false, emailAberto: false, x: 0, y: 0, cima: false,
               alternar(el) { const r = el.getBoundingClientRect(); this.cima = (window.innerHeight - r.bottom) < 250; this.x = window.innerWidth - r.right; this.y = this.cima ? window.innerHeight - r.top : r.bottom; this.aberto = ! this.aberto; } }"
     @keydown.escape.window="aberto = false" @scroll.window="aberto = false" @resize.window="aberto = false">
    <a href="{{ route('notas.danfse', $nota) }}" target="_blank" rel="noopener" class="btn-acao" title="Abrir o DANFSe (PDF) em outra aba">PDF</a>

    <button type="button" @click="alternar($el)" :aria-expanded="aberto" aria-haspopup="menu" aria-label="Mais ações da NFS-e {{ $nota->numero_nfse }}" title="Mais ações" class="btn-acao !px-0 w-[34px]">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
    </button>

    <div x-show="aberto" x-cloak x-transition.opacity.duration.100ms @click.outside="aberto = false" role="menu"
         :style="`position: fixed; right: ${x}px; ${cima ? 'bottom: ' + (y + 6) : 'top: ' + (y + 6)}px`"
         class="z-[60] w-60 rounded-xl bg-white border border-linha shadow-[0_12px_32px_rgba(14,42,32,.22)] py-1.5 text-left">
        <a href="{{ route('notas.xml', $nota) }}" class="{{ $item }}" role="menuitem">
            <svg class="w-4 h-4 text-ink-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></svg>Baixar o XML
        </a>
        <button type="button" class="{{ $item }}" role="menuitem" data-copiar="{{ $nota->chave_acesso }}" data-aviso="Chave de acesso copiada" @click="aberto = false">
            <svg class="w-4 h-4 text-ink-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h9"/></svg>Copiar a chave de acesso
        </button>
        @can('emitir')
            <button type="button" role="menuitem" class="{{ $item }}" @click="aberto = false; $dispatch('abrir-email-{{ $nota->id }}')" title="Enviar o PDF e o XML por e-mail. Você pode trocar os destinatários só deste envio.">
                <svg class="w-4 h-4 text-ink-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                <span>{{ $emails ? 'Enviar por e-mail…' : 'Enviar por e-mail (informar)…' }}</span>
            </button>
        @endcan
        @can('cancelar-nota')
            <div class="my-1.5 border-t border-gray-100"></div>
            <a href="{{ route('notas.cancelar.form', $nota) }}" class="{{ $item }} !text-perigo-700 hover:!bg-perigo-50" role="menuitem" title="Cancelar esta NFS-e (ato fiscal, sem volta)">
                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>Cancelar a NFS-e…
            </a>
        @endcan
    </div>
</div>
@can('emitir')
    {{-- Envio por e-mail: já vem com os e-mails do cliente, mas dá para trocar só para este envio (ex.: mandar para o nosso e-mail para testar) --}}
    <div x-data="{ aberto: false }" x-on:abrir-email-{{ $nota->id }}.window="aberto = true" @keydown.escape.window="aberto = false">
        <div x-show="aberto" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="Enviar NFS-e por e-mail">
            <form method="POST" action="{{ route('notas.email', $nota) }}" @click.outside="aberto = false" class="card w-full max-w-md p-5 space-y-3 text-left">
                @csrf
                <h2 class="text-lg font-semibold">Enviar NFS-e nº {{ $nota->numero_nfse }} por e-mail</h2>
                <div>
                    <label for="emails-{{ $nota->id }}" class="campo-rotulo">Enviar para</label>
                    <input id="emails-{{ $nota->id }}" name="emails" value="{{ implode(', ', $emails) }}" class="campo-entrada" placeholder="email@cliente.com, outro@cliente.com" required>
                    <p class="campo-ajuda">Já vem com os e-mails do cliente. Pode trocar só para este envio (até 5, separados por vírgula).@if ($nota->emitente?->email_copia) A empresa ({{ $nota->emitente->email_copia }}) recebe cópia.@endif</p>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" class="btn" @click="aberto = false">Cancelar</button>
                    <button class="btn btn-primary">Enviar</button>
                </div>
            </form>
        </div>
    </div>
@endcan
@if ($nota->email_enviado_em)
    <div class="text-xs text-ink-600 mt-1 sm:text-right">E-mail enviado em {{ $nota->email_enviado_em->timezone('America/Sao_Paulo')->format('d/m H:i') }}</div>
@endif
