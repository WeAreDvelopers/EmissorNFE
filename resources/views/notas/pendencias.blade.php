<x-app-layout titulo="Precisa de você">
    @php
        $f = fn ($n) => number_format($n, 0, ',', '.');
        $grupos = [
            null            => ['Todas', array_sum($contagem->all())],
            'recusadas'     => ['Recusadas pela prefeitura', (int) ($contagem['rejeitada'] ?? 0)],
            'invalidas'     => ['Dado inválido', (int) ($contagem['invalida'] ?? 0)],
            'instabilidade' => ['Instabilidade', (int) ($contagem['falhou'] ?? 0)],
        ];
    @endphp

    <div class="max-w-[1240px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div>
            <h1 class="text-3xl font-semibold">Precisa de você</h1>
            <p class="mt-1 text-[15px] text-ink-700">Notas de todas as importações que dependem de uma ação sua. Nenhuma delas gastou crédito.</p>
        </div>

        <div class="flex flex-wrap items-start gap-5">
            @php $versao = md5(json_encode($contagem).'|'.$grupo.'|'.($selecionada?->id).'|'.$notas->getCollection()->map(fn ($n) => $n->id.$n->status->value.$n->updated_at?->timestamp)->implode(',')); @endphp
            <section class="card flex-[999_1_620px] min-w-0 pt-4 pb-1.5" data-regiao="lista" data-versao="{{ $versao }}">
                <div class="px-5 sm:px-6 pb-3.5 flex flex-wrap gap-2">
                    @foreach ($grupos as $chave => [$rotulo, $qtd])
                        <a href="{{ route('pendencias', array_filter(['grupo' => $chave])) }}" @class(['inline-flex items-center gap-1.5 min-h-[40px] px-3.5 rounded-full border text-sm no-underline', 'bg-ink-900 text-white border-ink-900' => $grupo === $chave, 'bg-white text-ink-900 border-gray-300 hover:bg-gray-50' => $grupo !== $chave])>{{ $rotulo }} <span class="opacity-80">{{ $f($qtd) }}</span></a>
                    @endforeach
                </div>
                @forelse ($notas as $n)
                    <x-nota-linha :nota="$n" :mostrar-lote="true" :href="route('pendencias', array_filter(['grupo' => $grupo, 'nota' => $n->id]))" :selecionada="$selecionada?->id === $n->id" />
                @empty
                    <div class="px-6 py-12 text-center tabela-linha">
                        <p class="text-lg font-medium">Tudo em dia.</p>
                        <p class="text-ink-700 mt-1">Nenhuma nota precisa de você agora.</p>
                    </div>
                @endforelse
                <div class="px-5 sm:px-6 py-3.5 tabela-linha">{{ $notas->links() }}</div>
            </section>

            <div id="painel-vaga" class="flex-[1_1_380px] min-w-0 max-w-[460px] empty:hidden">
                @if ($selecionada && $selecionada->status->grupo() === 'atencao')
                    @include('notas._painel', ['nota' => $selecionada, 'fechar' => route('pendencias', array_filter(['grupo' => $grupo]))])
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
