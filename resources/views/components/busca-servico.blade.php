@props(['nome', 'rotulo' => 'Código de serviço (6 dígitos)', 'valor' => null, 'ajuda' => null, 'id' => null, 'somenteLeitura' => 'false',
        'url' => null, 'obrigatorio' => false, 'tam' => 6, 'evento' => 'definir-servico', 'vazio' => 'Selecione o serviço (código ou nome da atividade)', 'nenhum' => 'Nenhum serviço encontrado. Tente outra palavra.'])
@php $id = $id ?: $nome; @endphp
{{-- Estilo select2: o gatilho mostra "01.07.01 - Descrição" junto (como no portal do governo); ao clicar abre a lista com busca.
     O valor enviado é sempre o código de 6 dígitos. --}}
<div x-data="buscaServico(@js((string) $valor), @js($url ?: route('servicos.buscar')), {{ (int) $tam }})" @click.outside="aberto = false" @keydown.escape="aberto = false"
     x-on:{{ $evento }}.window="definir($event.detail)" {{ $attributes->class(['relative']) }}>
    @if ($rotulo)<label for="{{ $id }}" class="campo-rotulo {{ $obrigatorio ? 'obrigatorio' : '' }}">{{ $rotulo }}</label>@endif
    <input type="hidden" name="{{ $nome }}" :value="texto">

    <button type="button" id="{{ $id }}" x-ref="gatilho" @click="aberto ? aberto = false : abrir()" :disabled="{{ $somenteLeitura }}"
            class="campo-entrada !mt-1 w-full text-left flex items-stretch justify-between gap-0 cursor-pointer !p-0 overflow-hidden border border-gray-400 bg-white hover:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:border-brand-600 aria-expanded:border-brand-600 aria-expanded:ring-2 aria-expanded:ring-brand-600 disabled:cursor-default disabled:bg-gray-100 disabled:text-ink-700 disabled:hover:border-gray-400"
            aria-haspopup="listbox" :aria-expanded="aberto">
        <span class="min-w-0 flex-1 px-3.5 py-2.5">
            <template x-if="texto">
                <span><span class="inline-block rounded-md bg-brand-50 px-1.5 py-0.5 font-mono font-semibold text-brand-700 align-baseline" x-text="fmt(texto)"></span><span class="text-ink-900" x-show="descricao"> - <span x-text="descricao"></span></span></span>
            </template>
            <span x-show="!texto" class="text-ink-600">{{ $vazio }}</span>
        </span>
        <span class="flex items-center px-3 border-s border-gray-300 bg-gray-50 text-ink-700 group-hover:bg-gray-100"><svg class="w-4 h-4 shrink-0 transition-transform" :class="aberto ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span>
    </button>

    <div x-show="aberto" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-gray-400 bg-white shadow-[0_12px_32px_rgba(14,42,32,.22)]">
        <div class="p-2 border-b border-gray-100">
            <input x-ref="busca" x-model="termo" @input.debounce.200ms="buscar()" @keydown.arrow-down.prevent="mover(1)" @keydown.arrow-up.prevent="mover(-1)"
                   @keydown.enter.prevent="escolher(itens[indice])" autocomplete="off" placeholder="Buscar por código ou nome" class="campo-entrada font-mono" role="combobox" aria-controls="lista-{{ $id }}">
        </div>
        <ul x-ref="lista" id="lista-{{ $id }}" class="max-h-72 overflow-auto" role="listbox">
            <template x-for="(i, n) in itens" :key="i.codigo">
                <li role="option" :aria-selected="i.codigo === texto">
                    <button type="button" @click="escolher(i)" @mousemove="indice = n"
                            :class="n === indice ? 'bg-gray-100' : (i.codigo === texto ? 'bg-brand-50' : '')"
                            class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 border-b border-gray-100 last:border-0">
                        <span class="font-mono font-semibold" x-text="fmt(i.codigo)"></span> <span class="text-ink-700" x-text="i.descricao"></span>
                    </button>
                </li>
            </template>
            <li x-show="!itens.length" class="px-3 py-2 text-sm text-ink-600">{{ $nenhum }}</li>
        </ul>
        <button type="button" x-show="texto" @click="limpar()" class="w-full text-left px-3 py-2 text-[13px] text-ink-600 border-t border-gray-100 hover:bg-gray-50">Limpar seleção</button>
    </div>
    @if ($ajuda)<p class="campo-ajuda" x-show="!texto">{{ $ajuda }}</p>@endif
</div>
