<x-app-layout titulo="Notas">
    @php $f = fn ($n) => number_format($n, 0, ',', '.'); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Notas</h1>
                <p class="mt-1 text-[15px] text-ink-700">Todas as notas enviadas, de todas as importações. Busque por cliente, número da NFS-e ou chave.</p>
            </div>
            <a href="{{ route('relatorios.index') }}" class="btn">Relatório do mês</a>
        </div>

        @php $temFiltro = collect($filtros)->filter(fn ($v) => filled($v) && $v !== 'todas')->isNotEmpty(); @endphp
        <form method="GET" class="card p-4 sm:p-5 space-y-4">
            {{-- Linha 1: o que procurar. Linha 2: onde e quando. Os botões ficam sempre dentro do cartão. --}}
            <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_200px] gap-4">
                <div>
                    <label for="q" class="campo-rotulo">Buscar</label>
                    <input id="q" name="q" value="{{ $filtros['q'] ?? '' }}" placeholder="Cliente, CNPJ/CPF, nº da NFS-e ou chave" class="campo-entrada" autocomplete="off">
                </div>
                <div>
                    <label for="grupo" class="campo-rotulo">Situação</label>
                    <select id="grupo" name="grupo" class="campo-entrada">@foreach ($grupos as $v => $r)<option value="{{ $v }}" @selected(($filtros['grupo'] ?? 'todas') === $v)>{{ $r }}</option>@endforeach</select>
                </div>
            </div>
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-full sm:w-[260px]">
                    <label for="emitente" class="campo-rotulo">Empresa</label>
                    <select id="emitente" name="emitente" class="campo-entrada"><option value="">Todas</option>@foreach ($emitentes as $e)<option value="{{ $e->id }}" @selected(($filtros['emitente'] ?? '') == $e->id)>{{ \Illuminate\Support\Str::limit($e->razao_social, 32) }}</option>@endforeach</select>
                </div>
                <div class="w-[calc(50%-8px)] sm:w-[178px]">
                    <label for="de" class="campo-rotulo">Competência de</label>
                    <input id="de" name="de" type="date" value="{{ $filtros['de'] ?? '' }}" class="campo-entrada">
                </div>
                <div class="w-[calc(50%-8px)] sm:w-[178px]">
                    <label for="ate" class="campo-rotulo">até</label>
                    <input id="ate" name="ate" type="date" value="{{ $filtros['ate'] ?? '' }}" class="campo-entrada">
                </div>
                <div class="flex items-center gap-3 sm:ms-auto">
                    @if ($temFiltro)<a href="{{ route('notas.index') }}" class="text-sm text-ink-700 hover:underline">Limpar filtros</a>@endif
                    <button class="btn btn-primary">Filtrar</button>
                </div>
            </div>
        </form>

        <div class="card pt-1 overflow-hidden">
            <div class="px-5 sm:px-6 py-3 text-sm text-ink-700 flex flex-wrap justify-between gap-2">
                <span>{{ $f($notas->total()) }} {{ $notas->total() === 1 ? 'nota' : 'notas' }}</span>
                {{-- Pendentes = na fila, aguardando crédito ou com erro (ainda não emitidas) --}}
                <span title="Pendentes: notas na fila, aguardando crédito ou com erro">Notas nesse filtro: Emitidas <b class="font-mono text-ink-900">R$ {{ number_format($totalEmitidas, 2, ',', '.') }}</b> | Pendentes <b class="font-mono text-ink-900">R$ {{ number_format($totalPendentes, 2, ',', '.') }}</b></span>
            </div>
            {{-- Cabeçalho com ordenação: clique para ordenar, clique de novo para inverter --}}
            <div class="hidden sm:grid px-5 sm:px-6 py-2 grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_104px_150px_112px] gap-x-4 text-xs uppercase tracking-wide border-y border-linha bg-chao/60">
                <x-ordenavel col="cliente" :ordem="$ordem" :dir="$dir">Cliente</x-ordenavel>
                <x-ordenavel col="competencia" :ordem="$ordem" :dir="$dir">Competência</x-ordenavel>
                <x-ordenavel col="valor" :ordem="$ordem" :dir="$dir" class="justify-self-end">Valor</x-ordenavel>
                <x-ordenavel col="situacao" :ordem="$ordem" :dir="$dir">Situação</x-ordenavel>
                <x-ordenavel col="nfse" :ordem="$ordem" :dir="$dir" class="justify-self-end">NFS-e nº</x-ordenavel>
            </div>
            @forelse ($notas as $n)
                <div class="tabela-linha px-5 sm:px-6 py-3.5 grid grid-cols-1 sm:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_104px_150px_112px] gap-x-4 gap-y-1.5 items-center text-sm">
                    <div class="min-w-0">
                        <div class="font-medium truncate">{{ $n->tomador_rotulo }}</div>
                        <div class="text-xs text-ink-600 truncate">@unless ($n->sem_tomador)<span class="font-mono">{{ \App\Support\Documento::formatar($n->tomador_documento) }}</span> · @endunless{{ $n->emitente?->razao_social }}</div>
                    </div>
                    <div class="text-xs text-ink-600">
                        Competência {{ $n->data_competencia?->format('d/m/Y') }}
                        @if ($n->importacao)<div><a href="{{ route('importacoes.show', $n->importacao) }}" class="link text-xs">{{ \Illuminate\Support\Str::limit($n->importacao->arquivo_nome, 26) }}</a></div>@endif
                    </div>
                    <div class="font-mono sm:text-right">R$ {{ number_format($n->valor, 2, ',', '.') }}</div>
                    <div>
                        @php $dica = $n->status->grupo() === 'atencao' ? \App\Support\ExplicacaoErro::para($n) : null; @endphp
                        <span class="pill {{ $n->status->selo() }}" @if ($dica) title="{{ $dica['titulo'] }}: {{ \Illuminate\Support\Str::limit($dica['o_que_aconteceu'], 220) }}" @endif>{{ $n->status->label() }}</span>
                        @if (in_array($n->status, [\App\Enums\StatusNota::Emitida, \App\Enums\StatusNota::Cancelada], true) && $n->numero_nfse)<div class="text-[13px] mt-1">NFS-e nº <b class="font-mono">{{ $n->numero_nfse }}</b></div>@endif
                        @if ($n->status === \App\Enums\StatusNota::Cancelada)<div class="text-xs text-ink-600 mt-0.5">Cancelada em {{ $n->cancelada_em?->timezone('America/Sao_Paulo')->format('d/m/Y') }}</div>@endif
                    </div>
                    <div class="sm:text-right">
                        @if ($n->status === \App\Enums\StatusNota::Emitida && $n->xml_nfse)
                            <x-nota-documentos :nota="$n" />
                        @elseif (in_array($n->status, [\App\Enums\StatusNota::Falhou, \App\Enums\StatusNota::Enviando, \App\Enums\StatusNota::Pendente], true) && $n->numero_dps)
                            @can('emitir')
                                <form method="POST" action="{{ route('notas.conciliar', $n) }}">@csrf<button class="btn-acao" title="Pergunta à SEFIN se esta nota já foi autorizada">Conferir</button></form>
                            @endcan
                        @elseif ($n->status->grupo() === 'atencao' && $n->importacao)
                            <a href="{{ route('importacoes.show', [$n->importacao, 'filtro' => 'atencao', 'nota' => $n->id]) }}" class="font-semibold text-brand-600 no-underline hover:underline" title="Ver o erro e corrigir">Ver erro →</a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="tabela-linha px-6 py-10 text-center text-ink-700">Nenhuma nota encontrada com esses filtros.</div>
            @endforelse
            <div class="px-5 sm:px-6 py-3.5 tabela-linha">{{ $notas->links() }}</div>
        </div>
    </div>
    @if ($emAndamento)
        {{-- Há notas na fila: atualiza sozinho (sem perder filtro nem ordenação) até todas saírem --}}
        <script>setTimeout(() => { if (!document.querySelector('input:focus, select:focus')) location.reload(); }, 6000);</script>
    @endif
</x-app-layout>
