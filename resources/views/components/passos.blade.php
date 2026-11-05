@props(['atual'])
<ol class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm" aria-label="Etapas da importação">
    @foreach (['Escolher arquivo', 'Conferir', 'Enviar'] as $i => $rotulo)
        @php $n = $i + 1; @endphp
        <li class="flex items-center gap-2 {{ $n === $atual ? 'font-semibold' : ($n < $atual ? 'text-ok-800' : 'text-ink-600') }}" @if($n === $atual) aria-current="step" @endif>
            <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs {{ $n < $atual ? 'bg-brand-600 text-white' : ($n === $atual ? 'bg-ink-900 text-white' : 'bg-gray-200') }}" aria-hidden="true">
                @if ($n < $atual)<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg>@else{{ $n }}@endif
            </span>{{ $rotulo }}
        </li>
        @if (! $loop->last)<li class="text-gray-400" aria-hidden="true">—</li>@endif
    @endforeach
</ol>
