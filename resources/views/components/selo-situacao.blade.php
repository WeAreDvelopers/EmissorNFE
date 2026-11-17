{{-- Selo da situação da nota. Com $dica (nota que precisa de ação) abre o tooltip com o que fazer; sem, é só o selo. --}}
@props(['nota', 'dica' => null])
@if ($dica)
    {{-- Tooltip próprio (posição fixa, para não ser cortado pela borda da tabela); abre com o mouse ou com o foco do teclado. --}}
    <span class="inline-block" x-data="{ aberto: false, x: 0, y: 0, cima: false,
            abrir(el) { const r = el.getBoundingClientRect(); this.cima = (window.innerHeight - r.bottom) < 260; this.x = Math.max(8, Math.min(r.left, window.innerWidth - 336)); this.y = this.cima ? window.innerHeight - r.top : r.bottom; this.aberto = true; } }"
          @keydown.escape.window="aberto = false" @scroll.window="aberto = false" @resize.window="aberto = false">
        <span class="pill {{ $nota->status->selo() }} cursor-help" tabindex="0" aria-describedby="dica-{{ $nota->id }}"
              @mouseenter="abrir($el)" @mouseleave="aberto = false" @focus="abrir($el)" @blur="aberto = false">{{ $dica['rotulo'] ?? $nota->status->label() }}</span>
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
