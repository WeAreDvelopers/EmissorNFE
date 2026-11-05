@props(['nome', 'rotulo', 'tipo' => 'text', 'valor' => null, 'ajuda' => null, 'mono' => false])
<div {{ $attributes->only('class') }}>
    <label for="{{ $nome }}" class="campo-rotulo">{{ $rotulo }}</label>
    @if ($tipo === 'select')
        <select id="{{ $nome }}" name="{{ $nome }}" {{ $attributes->except('class') }} class="campo-entrada">{{ $slot }}</select>
    @else
        <input id="{{ $nome }}" name="{{ $nome }}" type="{{ $tipo }}" value="{{ $tipo === 'password' || $tipo === 'file' ? '' : old(str_replace(['[', ']'], ['.', ''], $nome), $valor) }}" {{ $attributes->except('class') }} class="campo-entrada {{ $mono ? 'font-mono' : '' }}">
    @endif
    @if ($ajuda)<p class="campo-ajuda">{{ $ajuda }}</p>@endif
</div>
