<x-app-layout titulo="Relatório do mês">
    @php $f = fn ($n) => number_format($n, 0, ',', '.'); $brl = fn ($v) => 'R$ '.number_format($v, 2, ',', '.'); $mesRotulo = $inicio->translatedFormat('F \d\e Y'); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Relatório do mês</h1>
                <p class="mt-1 text-[15px] text-ink-700">Notas por mês de competência, para conferir com a contabilidade.</p>
            </div>
            <a href="{{ route('relatorios.csv', array_filter(['mes' => $mes, 'emitente' => $empresa])) }}" class="btn btn-primary">Baixar planilha (CSV)</a>
        </div>

        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div>
                <label for="mes" class="campo-rotulo">Mês</label>
                <input id="mes" name="mes" type="month" value="{{ $mes }}" class="campo-entrada">
            </div>
            <div class="min-w-[220px]">
                <label for="emitente" class="campo-rotulo">Empresa</label>
                <select id="emitente" name="emitente" class="campo-entrada"><option value="">Todas</option>@foreach ($emitentes as $e)<option value="{{ $e->id }}" @selected($empresa == $e->id)>{{ \Illuminate\Support\Str::limit($e->razao_social, 34) }}</option>@endforeach</select>
            </div>
            <button class="btn btn-primary">Ver</button>
        </form>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="card p-5"><div class="text-sm text-ink-600">Emitidas em {{ $mesRotulo }}</div><div class="text-4xl font-semibold font-mono text-ok-800 mt-1">{{ $f($qtdEmitidas) }}</div><div class="text-[13px] text-ink-600 mt-1">notas autorizadas</div></div>
            <div class="card p-5"><div class="text-sm text-ink-600">Valor emitido</div><div class="text-3xl font-semibold font-mono mt-1.5">{{ $brl($valorEmitido) }}</div><div class="text-[13px] text-ink-600 mt-1.5">{{ $brl($semDestinatario) }} sem destinatário</div></div>
            <div class="card p-5"><div class="text-sm text-ink-600">Precisam de você</div><div class="text-4xl font-semibold font-mono mt-1 {{ ($recusadas + $invalidas + $falhas) ? 'text-warn-800' : '' }}">{{ $f($recusadas + $invalidas + $falhas) }}</div><div class="text-[13px] text-ink-600 mt-1">{{ $f($recusadas) }} recusadas · {{ $f($invalidas) }} inválidas · {{ $f($falhas) }} falhas</div></div>
            <div class="card p-5"><div class="text-sm text-ink-600">Em andamento</div><div class="text-4xl font-semibold font-mono mt-1">{{ $f($andamento) }}</div><div class="text-[13px] text-ink-600 mt-1">ainda sem resultado</div></div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
            <section class="card pt-5 pb-1.5">
                <h2 class="text-lg font-semibold px-5 sm:px-6 pb-3">Por cliente</h2>
                @forelse ($porCliente as $c)
                    <div class="tabela-linha px-5 sm:px-6 py-2.5 flex justify-between gap-3 text-sm">
                        <div class="min-w-0"><div class="truncate">{{ $c->sem_tomador ? 'Sem destinatário informado' : $c->nome }}</div><div class="text-xs text-ink-600">{{ $f($c->qtd) }} {{ $c->qtd == 1 ? 'nota' : 'notas' }}</div></div>
                        <span class="font-mono shrink-0">{{ $brl($c->total) }}</span>
                    </div>
                @empty
                    <div class="tabela-linha px-6 py-8 text-center text-ink-700">Nenhuma nota emitida neste mês.</div>
                @endforelse
            </section>

            <section class="card pt-5 pb-1.5">
                <h2 class="text-lg font-semibold px-5 sm:px-6 pb-3">Por dia de competência</h2>
                @forelse ($porDia as $d)
                    <div class="tabela-linha px-5 sm:px-6 py-2.5 flex justify-between gap-3 text-sm">
                        <div>{{ \Illuminate\Support\Carbon::parse($d->dia)->format('d/m/Y') }} <span class="text-xs text-ink-600">· {{ $f($d->qtd) }} {{ $d->qtd == 1 ? 'nota' : 'notas' }}</span></div>
                        <span class="font-mono">{{ $brl($d->total) }}</span>
                    </div>
                @empty
                    <div class="tabela-linha px-6 py-8 text-center text-ink-700">Sem notas neste mês.</div>
                @endforelse
            </section>
        </div>
    </div>
</x-app-layout>
