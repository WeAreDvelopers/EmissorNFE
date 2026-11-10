@props(['nome', 'rotulo', 'tipo' => 'text', 'valor' => null, 'ajuda' => null, 'mono' => false, 'obrigatorio' => false])
@php
    $chave = str_replace(['[', ']'], ['.', ''], $nome);
    $erro = $errors->first($chave);
    $marca = $obrigatorio || $attributes->has('required');   // "obrigatorio" só marca o asterisco; "required" também aciona a validação do navegador
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $nome }}" class="campo-rotulo {{ $marca ? 'obrigatorio' : '' }}">{{ $rotulo }}</label>
    @if ($tipo === 'select')
        <select id="{{ $nome }}" name="{{ $nome }}" {{ $attributes->except('class') }} class="campo-entrada {{ $erro ? 'campo-invalido' : '' }}" @if ($erro) aria-invalid="true" @endif>{{ $slot }}</select>
    @else
        <input id="{{ $nome }}" name="{{ $nome }}" type="{{ $tipo }}" value="{{ $tipo === 'password' || $tipo === 'file' ? '' : old($chave, $valor) }}" {{ $attributes->except('class') }} class="campo-entrada {{ $mono ? 'font-mono' : '' }} {{ $erro ? 'campo-invalido' : '' }}" @if ($erro) aria-invalid="true" @endif>
    @endif
    @if ($erro)<p class="erro-campo" role="alert">{{ $erro }}</p>@endif
    @if ($ajuda)<p class="campo-ajuda">{{ $ajuda }}</p>@endif
</div>
