@props(['nota', 'href', 'selecionada' => false, 'mostrarLote' => false])
@php $precisa = $nota->status->grupo() === 'atencao'; @endphp
<div @class(['grid grid-cols-[44px_minmax(0,1fr)] sm:grid-cols-[48px_minmax(0,1.4fr)_100px_minmax(0,1.5fr)_112px] gap-x-4 gap-y-1 items-center px-5 sm:px-6 py-3 tabela-linha text-sm', 'bg-warn-50' => $selecionada])>
    <span class="font-mono text-ink-600">{{ $nota->linha_planilha ?: '—' }}</span>
    <div class="min-w-0">
        <div class="font-medium truncate">{{ $nota->tomador_rotulo }}</div>
        <div class="font-mono text-xs text-ink-600">{{ \App\Support\Documento::formatar($nota->tomador_documento) }}@if ($mostrarLote && $nota->importacao) · {{ $nota->importacao->arquivo_nome }}@endif</div>
    </div>
    <span class="font-mono sm:text-right col-start-2 sm:col-start-auto">R$ {{ number_format($nota->valor, 2, ',', '.') }}</span>
    <div class="col-start-2 sm:col-start-auto">
        @php $dica = $precisa ? \App\Support\ExplicacaoErro::para($nota) : null; @endphp
        @if ($dica)
            {{-- Tooltip próprio (posição fixa, para não ser cortado pela borda da tabela); abre com o mouse ou com o foco do teclado. --}}
            <span class="inline-block" x-data="{ aberto: false, x: 0, y: 0, cima: false,
                    abrir(el) { const r = el.getBoundingClientRect(); this.cima = (window.innerHeight - r.bottom) < 260; this.x = Math.max(8, Math.min(r.left, window.innerWidth - 336)); this.y = this.cima ? window.innerHeight - r.top : r.bottom; this.aberto = true; } }"
                  @keydown.escape.window="aberto = false" @scroll.window="aberto = false" @resize.window="aberto = false">
                <span class="pill {{ $nota->status->selo() }} cursor-help" tabindex="0" aria-describedby="dica-{{ $nota->id }}"
                      @mouseenter="abrir($el)" @mouseleave="aberto = false" @focus="abrir($el)" @blur="aberto = false">{{ $nota->status->label() }}</span>
                <div x-show="aberto" x-cloak x-transition.opacity.duration.100ms id="dica-{{ $nota->id }}" role="tooltip"
                     :style="`position: fixed; left: ${x}px; ${cima ? 'bottom: ' + (y + 8) : 'top: ' + (y + 8)}px`"
                     class="z-[70] w-80 max-w-[calc(100vw-16px)] rounded-xl bg-ink-900 text-white shadow-[0_12px_32px_rgba(14,42,32,.35)] px-4 py-3 text-[13px] leading-snug text-left font-normal pointer-events-none">
                    <div class="font-semibold mb-1">{{ $dica['titulo'] }}</div>
                    <p>{{ $dica['o_que_aconteceu'] }}</p>
                    @if (! empty($dica['tecnico']))
                        <p class="mt-2 text-[12px] text-white/70 font-mono break-words">{{ \Illuminate\Support\Str::limit(implode(' | ', (array) $dica['tecnico']), 240) }}</p>
                    @endif
                    @if (! empty($dica['como_resolver']))
                        <p class="mt-2 text-white/90"><b>O que fazer:</b> {{ \Illuminate\Support\Str::limit($dica['como_resolver'], 200) }}</p>
                    @endif
                </div>
            </span>
        @else
            <span class="pill {{ $nota->status->selo() }}">{{ $nota->status->label() }}</span>
        @endif
        @if ($nota->status === \App\Enums\StatusNota::Emitida && $nota->numero_nfse)<div class="text-[13px] text-ink-900 mt-1" @if ($nota->chave_acesso) title="Chave de acesso: {{ $nota->chave_acesso }}" @endif>NFS-e nº <b class="font-mono">{{ $nota->numero_nfse }}</b></div>@endif
        @if ($nota->status === \App\Enums\StatusNota::Cancelada)<div class="text-xs text-ink-600 mt-1" title="{{ $nota->cancelamento_motivo }}">NFS-e nº {{ $nota->numero_nfse }} · cancelada em {{ $nota->cancelada_em?->timezone('America/Sao_Paulo')->format('d/m') }}</div>@endif
        @if ($nota->status === \App\Enums\StatusNota::Enviando)<div class="text-xs text-ink-600 mt-1">Tentativa {{ max($nota->tentativas, 1) }}</div>@endif
    </div>
    <div class="col-start-2 sm:col-start-auto sm:text-right">
        @if ($nota->status === \App\Enums\StatusNota::Emitida && $nota->xml_nfse)
            <x-nota-documentos :nota="$nota" />
        @endif
        @if ($precisa)
            <a href="{{ $href }}" data-painel class="font-semibold text-brand-600 no-underline hover:underline">{{ $nota->status === \App\Enums\StatusNota::Falhou ? 'Reenviar' : 'Corrigir' }} →</a>
        @endif
    </div>
</div>
