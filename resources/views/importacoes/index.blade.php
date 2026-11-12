<x-app-layout titulo="Importações">
    @php
        $porStatus = \App\Models\NotaFiscal::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $emitidasMes = \App\Models\NotaFiscal::where('status', 'emitida')->where('emitida_em', '>=', now()->startOfMonth())->count();
        $naFila = (int) ($porStatus['pendente'] ?? 0) + (int) ($porStatus['enviando'] ?? 0) + (int) ($porStatus['aguardando_credito'] ?? 0);
        $atencao = (int) ($porStatus['invalida'] ?? 0) + (int) ($porStatus['rejeitada'] ?? 0) + (int) ($porStatus['falhou'] ?? 0);
    @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Importações</h1>
                <p class="mt-1 text-[15px] text-ink-700">Cada planilha vira um lote de notas para emitir.</p>
            </div>
            @can('emitir')<a href="{{ route('importacoes.criar') }}" class="btn btn-primary">+ Nova importação</a>@endcan
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="card p-5"><div class="text-sm text-ink-600">Emitidas no mês</div><div class="text-4xl font-semibold font-mono text-ok-800 mt-1">{{ number_format($emitidasMes, 0, ',', '.') }}</div></div>
            <div class="card p-5"><div class="text-sm text-ink-600">Em andamento</div><div class="text-4xl font-semibold font-mono mt-1">{{ number_format($naFila, 0, ',', '.') }}</div></div>
            <a href="{{ route('pendencias') }}" class="card p-5 no-underline text-inherit hover:bg-gray-50"><div class="text-sm text-ink-600">Precisam de você</div><div class="text-4xl font-semibold font-mono mt-1 {{ $atencao ? 'text-warn-800' : '' }}">{{ number_format($atencao, 0, ',', '.') }}</div></a>
        </div>

        <div class="card overflow-hidden">
            <div class="hidden sm:grid grid-cols-[minmax(0,1.3fr)_minmax(0,1.5fr)_auto] gap-4 px-6 py-3 text-xs font-medium text-ink-600 uppercase tracking-wide">
                <x-ordenavel col="arquivo" :ordem="$ordem" :dir="$dir">Arquivo</x-ordenavel><x-ordenavel col="data" :ordem="$ordem" :dir="$dir">Enviada em</x-ordenavel><x-ordenavel col="situacao" :ordem="$ordem" :dir="$dir" class="justify-self-end">Situação</x-ordenavel>
            </div>
            @forelse ($importacoes as $i)
                @php $r = $resumos[$i->id] ?? array_fill_keys(array_map(fn ($s) => $s->value, \App\Enums\StatusNota::cases()), 0); [$sit, $selo] = $i->situacao($r); @endphp
                <x-lote-linha :importacao="$i" :resumo="$r" :situacao="$sit" :selo="$selo" />
            @empty
                <div class="px-6 py-12 text-center tabela-linha">
                    <p class="text-ink-700">Nenhuma planilha enviada ainda.</p>
                    @can('emitir')<a href="{{ route('importacoes.criar') }}" class="btn btn-primary mt-4">Importar a primeira planilha</a>@endcan
                </div>
            @endforelse
        </div>
        {{ $importacoes->links() }}
    </div>
</x-app-layout>
