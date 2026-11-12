<x-app-layout titulo="Auditoria">
    @php $fmt = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'sim' : 'não') : ($v === null || $v === '' ? '—' : $v)); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div>
            <h1 class="text-3xl font-semibold">Auditoria</h1>
            <p class="mt-1 text-[15px] text-ink-700">Quem fez o quê na conta: mudanças fiscais, envios, correções, exclusões e usuários. O registro não pode ser alterado nem apagado.</p>
        </div>

        <form method="GET" class="card p-4 sm:p-5 flex flex-wrap gap-4 items-end">
            <div class="min-w-[220px]">
                <label for="acao" class="campo-rotulo">Ação</label>
                <select id="acao" name="acao" class="campo-entrada"><option value="">Todas</option>@foreach ($acoes as $v => $r)<option value="{{ $v }}" @selected($acao === $v)>{{ $r }}</option>@endforeach</select>
            </div>
            <div class="flex-1 min-w-[220px]">
                <label for="q" class="campo-rotulo">Buscar no texto</label>
                <input id="q" name="q" value="{{ $busca }}" class="campo-entrada" autocomplete="off">
            </div>
            <div class="flex items-center gap-3 sm:ms-auto">
                @if (filled($acao) || filled($busca))<a href="{{ route('auditoria.index') }}" class="text-sm text-ink-700 hover:underline">Limpar filtros</a>@endif
                <button class="btn btn-primary">Filtrar</button>
            </div>
        </form>

        <div class="card pt-1 overflow-hidden">
            @forelse ($registros as $r)
                <div class="tabela-linha px-5 sm:px-6 py-3.5 text-sm">
                    <div class="flex flex-wrap justify-between gap-x-4 gap-y-1">
                        <div><b>{{ $r->rotulo() }}</b> <span class="text-ink-600">· {{ $r->user?->name ?? 'sistema' }}</span></div>
                        <div class="text-xs text-ink-600 font-mono">{{ $r->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}@if ($r->ip) · {{ $r->ip }}@endif</div>
                    </div>
                    <div class="text-ink-700 mt-0.5">{{ $r->descricao }}</div>
                    @if ($r->detalhes)
                        <details class="mt-1.5">
                            <summary class="cursor-pointer text-[13px] text-brand-600">Ver detalhes</summary>
                            <div class="mt-1.5 text-[13px] space-y-0.5">
                                @foreach ($r->detalhes as $campo => $valor)
                                    <div>
                                        <b>{{ $campo }}:</b>
                                        @if (is_array($valor) && array_key_exists('de', $valor) && array_key_exists('para', $valor))
                                            <span class="text-ink-600">{{ $fmt($valor['de']) }}</span> → {{ $fmt($valor['para']) }}
                                        @else
                                            {{ $fmt($valor) }}
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            @empty
                <div class="tabela-linha px-6 py-10 text-center text-ink-700">Nada registrado ainda.</div>
            @endforelse
            <div class="px-5 sm:px-6 py-3.5 tabela-linha">{{ $registros->links() }}</div>
        </div>
    </div>
</x-app-layout>
