<x-app-layout titulo="Master · Visão geral">
    @php
        $f = fn ($n) => number_format($n, 0, ',', '.');
        $maxSerie = max(collect($serie)->max('total'), 1);
    @endphp

    <div class="max-w-[1240px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl font-semibold">Visão geral da plataforma</h1>
                    <span class="pill bg-info-100 text-info-800">Master · somente leitura</span>
                </div>
                <p class="mt-1 text-[15px] text-ink-700">Todos os clientes, notas e créditos. Números dos últimos {{ $dias }} dias, salvo onde indicado.</p>
            </div>
            <div class="flex gap-2" role="group" aria-label="Período">
                @foreach ($periodos as $p)
                    <a href="{{ route('master.visao', ['dias' => $p]) }}" @class(['inline-flex items-center min-h-[40px] px-3.5 rounded-full border text-sm no-underline', 'bg-ink-900 text-white border-ink-900' => $dias === $p, 'bg-white text-ink-900 border-gray-300 hover:bg-gray-50' => $dias !== $p])>{{ $p }} dias</a>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
            <a href="{{ route('master.clientes') }}" class="card p-5 no-underline text-inherit hover:bg-gray-50">
                <div class="text-sm text-ink-600">Clientes</div>
                <div class="text-4xl font-semibold font-mono mt-1">{{ $f($contas['ativas']) }}<span class="text-lg text-ink-600"> / {{ $f($contas['total']) }}</span></div>
                <div class="text-[13px] text-ink-600 mt-1">ativos · {{ $f($contas['com_atividade']) }} com importação em {{ $dias }} dias</div>
            </a>
            <div class="card p-5">
                <div class="text-sm text-ink-600">Notas emitidas</div>
                <div class="text-4xl font-semibold font-mono text-ok-800 mt-1">{{ $f($notas['emitidas']) }}</div>
                <div class="text-[13px] text-ink-600 mt-1">{{ $f($notas['hoje']) }} hoje · R$ {{ number_format($notas['valor'], 2, ',', '.') }} em valor</div>
            </div>
            <div class="card p-5">
                <div class="text-sm text-ink-600">Taxa de recusa</div>
                <div class="text-4xl font-semibold font-mono mt-1 {{ ($notas['taxa_recusa'] ?? 0) >= 20 ? 'text-warn-800' : '' }}">{{ $notas['taxa_recusa'] === null ? '—' : number_format($notas['taxa_recusa'], 1, ',', '').'%' }}</div>
                <div class="text-[13px] text-ink-600 mt-1">{{ $f($notas['recusadas']) }} recusadas · {{ $f($notas['falhas']) }} com falha técnica</div>
            </div>
            <div class="card p-5">
                <div class="text-sm text-ink-600">Na fila agora</div>
                <div class="text-4xl font-semibold font-mono mt-1">{{ $f($fila['pendente']) }}</div>
                <div class="text-[13px] mt-1 {{ $fila['aguardando_credito'] ? 'text-warn-800 font-medium' : 'text-ink-600' }}">{{ $f($fila['aguardando_credito']) }} aguardando crédito</div>
            </div>
            <div class="card p-5">
                <div class="text-sm text-ink-600">Empresas emissoras</div>
                <div class="text-4xl font-semibold font-mono mt-1">{{ $f($emitentes['total']) }}</div>
                <div class="text-[13px] text-ink-600 mt-1">{{ $f($emitentes['producao']) }} em produção · {{ $f($emitentes['homologacao']) }} em teste</div>
                @if ($emitentes['cert_vencido'] || $emitentes['cert_vencendo'])
                    <div class="text-[13px] text-warn-800 font-medium mt-1">{{ $f($emitentes['cert_vencido']) }} certificado vencido · {{ $f($emitentes['cert_vencendo']) }} vencendo em 30 dias</div>
                @endif
            </div>
            <div class="card p-5">
                <div class="text-sm text-ink-600">Créditos</div>
                <div class="text-4xl font-semibold font-mono mt-1">{{ $f($creditos['disponivel']) }}</div>
                <div class="text-[13px] text-ink-600 mt-1">{{ $f($creditos['reservado']) }} reservados · {{ $f($creditos['vendas_qtd']) }} compras, R$ {{ number_format($creditos['vendas_valor'], 2, ',', '.') }}</div>
            </div>
        </div>

        <section class="card p-5 sm:p-6">
            <h2 class="text-lg font-semibold">Notas emitidas por dia <span class="text-sm font-normal text-ink-600">· últimos 14 dias</span></h2>
            <div class="mt-4 flex items-end gap-1.5 h-36" role="img" aria-label="Gráfico de notas emitidas por dia">
                @foreach ($serie as $d)
                    <div class="flex-1 min-w-0 flex flex-col items-center justify-end h-full gap-1" title="{{ $d['dia']->format('d/m') }}: {{ $d['total'] }} notas">
                        <span class="text-[11px] font-mono text-ink-600">{{ $d['total'] ?: '' }}</span>
                        <div class="w-full rounded-t bg-brand-600" style="height: {{ max($d['total'] / $maxSerie * 100, $d['total'] ? 4 : 1) }}%; {{ $d['total'] ? '' : 'background:#D9DFD9' }}"></div>
                        <span class="text-[11px] text-ink-600">{{ $d['dia']->format('d/m') }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Erros mais comuns</h2>
                <p class="text-sm text-ink-600 mt-1">Notas recusadas ou com falha nos últimos {{ $dias }} dias.</p>
                @forelse ($erros as $e)
                    <div class="tabela-linha py-3 flex gap-3">
                        <span class="font-mono text-sm font-semibold w-14 shrink-0 {{ $e['codigo'] === 'Outros' ? 'text-ink-600' : 'text-warn-800' }}">{{ $e['codigo'] }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[13px] text-ink-700 leading-snug break-words">{{ $e['exemplo'] }}</div>
                            <div class="text-xs text-ink-600 mt-0.5">{{ $f($e['qtd']) }} {{ $e['qtd'] === 1 ? 'ocorrência' : 'ocorrências' }} · {{ $f($e['contas']) }} {{ $e['contas'] === 1 ? 'cliente' : 'clientes' }}</div>
                        </div>
                    </div>
                @empty
                    <p class="mt-4 text-sm text-ink-700">Nenhum erro no período. 🎉</p>
                @endforelse
            </section>

            <section class="card p-5 sm:p-6 space-y-5">
                <h2 class="text-lg font-semibold">Precisa de atenção</h2>

                <div>
                    <h3 class="text-sm font-semibold">Certificado vencido ou vencendo</h3>
                    @forelse ($atencao['certificados'] as $c)
                        <a href="{{ route('master.cliente', $c['conta_id']) }}" class="tabela-linha py-2 flex justify-between gap-3 text-sm no-underline text-inherit hover:bg-gray-50">
                            <span class="min-w-0 truncate">{{ $c['conta'] }} <span class="text-ink-600">· {{ $c['empresa'] }}</span></span>
                            <span @class(['shrink-0 font-medium', 'text-warn-800' => $c['validade']->isPast(), 'text-amber-900' => ! $c['validade']->isPast()])>{{ $c['validade']->isPast() ? 'venceu' : 'vence' }} {{ $c['validade']->format('d/m/Y') }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-ink-600 mt-1">Nenhum.</p>
                    @endforelse
                </div>

                <div>
                    <h3 class="text-sm font-semibold">Notas aguardando crédito</h3>
                    @forelse ($atencao['sem_saldo'] as $c)
                        <a href="{{ route('master.cliente', $c['conta_id']) }}" class="tabela-linha py-2 flex justify-between gap-3 text-sm no-underline text-inherit hover:bg-gray-50">
                            <span class="min-w-0 truncate">{{ $c['conta'] }}</span><span class="shrink-0 font-mono">{{ $f($c['total']) }} notas</span>
                        </a>
                    @empty
                        <p class="text-sm text-ink-600 mt-1">Nenhuma.</p>
                    @endforelse
                </div>

                <div>
                    <h3 class="text-sm font-semibold">Muitas recusas na semana (3 ou mais)</h3>
                    @forelse ($atencao['recusas'] as $c)
                        <a href="{{ route('master.cliente', $c['conta_id']) }}" class="tabela-linha py-2 flex justify-between gap-3 text-sm no-underline text-inherit hover:bg-gray-50">
                            <span class="min-w-0 truncate">{{ $c['conta'] }}</span><span class="shrink-0 font-mono text-warn-800">{{ $f($c['total']) }} recusadas</span>
                        </a>
                    @empty
                        <p class="text-sm text-ink-600 mt-1">Nenhuma.</p>
                    @endforelse
                </div>
            </section>
        </div>

        @if ($importacoes['falhas']->isNotEmpty() || $importacoes['travadas']->isNotEmpty())
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Importações com problema</h2>
                @foreach ([['falhas', 'Falharam na leitura (7 dias)'], ['travadas', 'Travadas há mais de 15 minutos']] as [$chave, $rotulo])
                    @if ($importacoes[$chave]->isNotEmpty())
                        <h3 class="text-sm font-semibold mt-3">{{ $rotulo }}</h3>
                        @foreach ($importacoes[$chave] as $i)
                            <a href="{{ route('master.cliente', $i->conta_id) }}" class="tabela-linha py-2 flex justify-between gap-3 text-sm no-underline text-inherit hover:bg-gray-50">
                                <span class="min-w-0 truncate">{{ $i->conta?->nome ?? '—' }} <span class="text-ink-600">· {{ $i->arquivo_nome }}</span></span>
                                <span class="shrink-0 text-ink-600">{{ $i->created_at->format('d/m H:i') }}</span>
                            </a>
                        @endforeach
                    @endif
                @endforeach
            </section>
        @endif
    </div>
</x-app-layout>
