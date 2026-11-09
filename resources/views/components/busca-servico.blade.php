@props(['nome', 'rotulo' => 'Código de serviço (6 dígitos)', 'valor' => null, 'ajuda' => null, 'id' => null])
@php $id = $id ?: $nome; @endphp
<div x-data="buscaServico(@js((string) $valor), @js(route('servicos.buscar')))" @click.outside="aberto = false" class="relative">
    @if ($rotulo)<label for="{{ $id }}" class="campo-rotulo">{{ $rotulo }}</label>@endif
    <input id="{{ $id }}" name="{{ $nome }}" x-model="texto" @input.debounce.250ms="buscar()" @focus="buscar()"
           @keydown.escape="aberto = false" @keydown.enter="if (aberto && itens.length) { $event.preventDefault(); escolher(itens[0]); }"
           autocomplete="off" placeholder="Digite o código ou o nome da atividade" class="campo-entrada font-mono" role="combobox" aria-expanded="false" :aria-expanded="aberto">
    <p class="campo-ajuda" x-show="descricao" x-text="descricao" x-cloak></p>
    @if ($ajuda)<p class="campo-ajuda" x-show="!descricao">{{ $ajuda }}</p>@endif
    <ul x-show="aberto" x-cloak class="absolute z-30 mt-1 w-full max-h-72 overflow-auto rounded-lg border border-gray-300 bg-white shadow-lg" role="listbox">
        <template x-for="i in itens" :key="i.codigo">
            <li><button type="button" @click="escolher(i)" class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 border-b border-gray-100 last:border-0">
                <span class="font-mono font-semibold" x-text="i.codigo"></span> <span class="text-ink-700" x-text="i.descricao"></span>
            </button></li>
        </template>
        <li x-show="!itens.length" class="px-3 py-2 text-sm text-ink-600">Nenhum serviço encontrado. Tente outra palavra.</li>
    </ul>
</div>
