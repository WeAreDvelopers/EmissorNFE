{{-- Modal de histórico: precisa de x-data com a variável indicada em "var" (o botão alterna). Padrão de todas as telas com histórico.
     A lista rola dentro do modal, que nunca passa da altura da tela. --}}
@props(['var' => 'historico', 'titulo' => 'Histórico de alterações'])
<div x-show="{{ $var }}" x-cloak @keydown.escape.window="{{ $var }} = false"
     class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50" role="dialog" aria-modal="true" aria-label="{{ $titulo }}">
    <div @click.outside="{{ $var }} = false" class="w-full max-w-2xl max-h-[85vh] flex flex-col rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-200 shrink-0">
            <h2 class="text-lg font-semibold text-ink-900">{{ $titulo }}</h2>
            <button type="button" @click="{{ $var }} = false" class="btn btn-sm" aria-label="Fechar">Fechar</button>
        </div>
        <div class="overflow-y-auto overscroll-contain px-5 pb-4 min-h-0">
            {{ $slot }}
        </div>
    </div>
</div>
