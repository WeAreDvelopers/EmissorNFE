@props(['msg' => null, 'titulo' => 'O que está errado'])
{{-- Valor com problema: em vermelho, com tooltip explicando o erro. Tooltip em posição fixa para não ser cortado pela borda da tabela;
     abre com o mouse ou com o foco do teclado. Sem erro, mostra só o conteúdo. --}}
@if ($msg)
    <span class="inline-block max-w-full" x-data="{ aberto: false, x: 0, y: 0, cima: false,
            abrir(el) { const r = el.getBoundingClientRect(); this.cima = (window.innerHeight - r.bottom) < 200; this.x = Math.max(8, Math.min(r.left, window.innerWidth - 328)); this.y = this.cima ? window.innerHeight - r.top : r.bottom; this.aberto = true; } }"
          @keydown.escape.window="aberto = false" @scroll.window="aberto = false" @resize.window="aberto = false">
        <span tabindex="0" class="inline-block max-w-full cursor-help rounded-md border border-perigo-200 bg-perigo-50 px-1.5 py-0.5 font-medium text-perigo-700 underline decoration-dotted underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-perigo-600"
              @mouseenter="abrir($el)" @mouseleave="aberto = false" @focus="abrir($el)" @blur="aberto = false" aria-invalid="true">{{ $slot }}</span>
        <div x-show="aberto" x-cloak x-transition.opacity.duration.100ms role="tooltip"
             :style="`position: fixed; left: ${x}px; ${cima ? 'bottom: ' + (y + 8) : 'top: ' + (y + 8)}px`"
             class="z-[70] w-80 max-w-[calc(100vw-16px)] rounded-xl bg-perigo-700 text-white shadow-[0_12px_32px_rgba(0,0,0,.35)] px-4 py-3 text-[13px] leading-snug text-left font-normal normal-case tracking-normal pointer-events-none">
            <div class="font-semibold mb-1">{{ $titulo }}</div>
            <p>{{ $msg }}</p>
        </div>
    </span>
@else
    {{ $slot }}
@endif
