@props(['col', 'ordem' => null, 'dir' => 'desc'])
@php
    // Clique ordena; novo clique inverte. O ícone fica sempre visível para mostrar que a coluna é clicável.
    $ativa = $ordem === $col;
    $url = request()->fullUrlWithQuery(['ordem' => $col, 'dir' => ($ativa && $dir === 'asc') ? 'desc' : 'asc', 'page' => null, 'pagina' => null]);
@endphp
<a href="{{ $url }}" {{ $attributes->class(['inline-flex items-center gap-1 no-underline text-ink-600 hover:text-ink-900 hover:underline cursor-pointer']) }}
   title="Ordenar por esta coluna" @if ($ativa) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
    {{ $slot }}<span aria-hidden="true" @class(['text-brand-600' => $ativa, 'opacity-60' => ! $ativa])>{{ $ativa ? ($dir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
</a>
