<x-app-layout :titulo="$importacao->arquivo_nome">
    @php
        $emAndamento = $importacao->status === 'enviando' || $andamento > 0;
        [$sit, $selo] = $importacao->situacao($resumo);
        $concluidas = $resumo['emitida'] + $resumo['cancelada'] + $atencao;
        $pct = fn ($n) => $total ? round($n / $total * 100, 1) : 0;
        $f = fn ($n) => number_format($n, 0, ',', '.');
        $filtros = ['todas' => ['Todas', $total], 'emitidas' => ['Emitidas', $resumo['emitida']], 'andamento' => ['Em andamento', $andamento], 'atencao' => ['Precisa de você', $atencao]];
    @endphp

    @php $versao = md5(json_encode($resumo).'|'.$filtro.'|'.($selecionada?->id).'|'.$notas->getCollection()->map(fn ($n) => $n->id.$n->status->value.$n->updated_at?->timestamp)->implode(',')); @endphp
    <div class="max-w-[1240px] mx-auto px-4 sm:px-8 py-7 space-y-6">

        <div class="space-y-2.5" data-regiao="cabecalho" data-versao="{{ md5(json_encode($resumo)) }}">
            @if ($emAndamento)<span data-poll hidden></span>@endif {{-- enquanto houver trabalho, a tela se atualiza sozinha (sem recarregar) --}}
            <a href="{{ route('importacoes.index') }}" class="link text-sm">← Importações</a>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-[28px] font-semibold">{{ $importacao->arquivo_nome }}</h1>
                    <span class="pill {{ $selo }}">{{ $sit }}</span>
                </div>
                @can('emitir')
                    @if ($importacao->foiEnviada())
                        <form method="POST" action="{{ route('importacoes.reprocessar', $importacao) }}">
                            @csrf
                            <button class="btn" title="Cria um novo lote com estas linhas para você editar e enviar de novo, sem precisar da planilha">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 019-9 9.8 9.8 0 016.7 2.8L21 8M21 3v5h-5M21 12a9 9 0 01-9 9 9.8 9.8 0 01-6.7-2.8L3 16M3 21v-5h5"/></svg>
                                Reprocessar
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
            @if ($importacao->reprocessadaDe)
                <div class="text-sm text-ink-600">Reprocessado a partir de <a class="link" href="{{ route('importacoes.show', $importacao->reprocessadaDe) }}">{{ $importacao->reprocessadaDe->arquivo_nome }}</a></div>
            @endif
            <div class="text-sm text-ink-600">Enviada {{ $importacao->created_at->isToday() ? 'hoje às '.$importacao->created_at->format('H:i') : 'em '.$importacao->created_at->format('d/m/Y H:i') }}@if ($importacao->emitente) · {{ $importacao->emitente->razao_social }}@endif @if ($emAndamento)· esta tela atualiza sozinha @endif</div>
        </div>

        <div class="card p-5 sm:px-6 space-y-3" data-regiao="progresso" data-versao="{{ md5(json_encode($resumo)) }}">
            <div class="flex flex-wrap justify-between gap-3 text-[15px]">
                <b>{{ $f($concluidas) }} de {{ $f($total) }} notas concluídas</b>
                @if ($minutos > 0)<span class="text-ink-600">Termina em cerca de {{ $minutos }} {{ $minutos === 1 ? 'minuto' : 'minutos' }}</span>@endif
            </div>
            <div class="h-2.5 rounded bg-gray-200 flex overflow-hidden" role="img" aria-label="{{ $resumo['emitida'] }} emitidas, {{ $atencao }} precisam de você, {{ $andamento }} em andamento">
                <span class="bg-brand-600" style="width: {{ $pct($resumo['emitida']) }}%"></span>
                <span class="bg-warn-600" style="width: {{ $pct($atencao) }}%"></span>
            </div>
            <div class="flex flex-wrap gap-5 text-[13px] text-ink-700">
                <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-brand-600 me-1.5"></span>{{ $f($resumo['emitida']) }} emitidas</span>
                <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-warn-600 me-1.5"></span>{{ $f($atencao) }} precisam de você</span>
                <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-gray-200 border border-gray-400 me-1.5"></span>{{ $f($andamento) }} em andamento</span>
            </div>
            @if ($resumo['aguardando_credito'])
                <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900 flex flex-wrap items-center gap-3 justify-between">
                    <span><b>{{ $f($resumo['aguardando_credito']) }} aguardando crédito.</b> Voltam para a fila sozinhas depois da recarga.</span>
                    @can('comprar-creditos')<a href="{{ route('creditos.index') }}" class="btn btn-primary btn-sm">Recarregar créditos</a>@endcan
                </div>
            @endif
        </div>

        @if ($importacao->total_planilha !== null)
            @php
                $emitidoValor = (float) $importacao->notas()->where('status', \App\Enums\StatusNota::Emitida)->sum('valor');
                $pendenteValor = (float) $importacao->notas()->whereNotIn('status', [\App\Enums\StatusNota::Emitida, \App\Enums\StatusNota::Rascunho])->sum('valor');
                $diferenca = round((float) $importacao->total_planilha - $emitidoValor - $pendenteValor, 2);
                $brl = fn ($v) => 'R$ '.number_format($v, 2, ',', '.');
            @endphp
            <div class="card p-5 sm:px-6" data-regiao="conciliacao" data-versao="{{ md5(json_encode($resumo)) }}">
                <h2 class="text-base font-semibold">Conferência com a planilha</h2>
                <div class="mt-2 grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div><div class="text-ink-600">Total da planilha</div><div class="font-mono text-lg">{{ $brl($importacao->total_planilha) }}</div></div>
                    <div><div class="text-ink-600">Emitido</div><div class="font-mono text-lg text-ok-800">{{ $brl($emitidoValor) }}</div></div>
                    <div><div class="text-ink-600">Ainda não emitido</div><div class="font-mono text-lg {{ $pendenteValor > 0 ? 'text-warn-800' : '' }}">{{ $brl($pendenteValor) }}</div></div>
                    <div><div class="text-ink-600">Diferença</div><div class="font-mono text-lg {{ abs($diferenca) > 0.009 ? 'text-warn-800' : 'text-ok-800' }}">{{ abs($diferenca) > 0.009 ? $brl($diferenca) : 'Fechou' }}</div></div>
                </div>
                @if (abs($diferenca) > 0.009)
                    <p class="mt-2 text-[13px] text-ink-700">A diferença é valor de linhas que saíram do lote (excluídas) ou que não batem com o total declarado na célula E1.</p>
                @endif
            </div>
        @endif

        <div data-regiao="atencao" data-versao="{{ md5(json_encode($resumo)) }}">
        @if ($atencao)
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Precisa de você · {{ $f($atencao) }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5">
                    @if ($resumo['rejeitada'])
                        <div class="card border-warn-200 p-4 space-y-2">
                            <div class="font-semibold">{{ $f($resumo['rejeitada']) }} recusadas pela prefeitura</div>
                            <div class="text-[13px] text-ink-700 leading-snug">Nenhuma gastou crédito. Abra uma para ver o motivo e como corrigir.</div>
                            <a href="{{ route('importacoes.show', [$importacao, 'filtro' => 'atencao']) }}" class="btn btn-sm">Ver e corrigir</a>
                        </div>
                    @endif
                    @if ($resumo['invalida'])
                        <div class="card border-warn-200 p-4 space-y-2">
                            <div class="font-semibold">{{ $f($resumo['invalida']) }} {{ $resumo['invalida'] === 1 ? 'linha com dado inválido' : 'linhas com dado inválido' }}</div>
                            <div class="text-[13px] text-ink-700 leading-snug">Não gastaram crédito. Corrija o campo indicado.</div>
                            <a href="{{ route('importacoes.show', [$importacao, 'filtro' => 'atencao']) }}" class="btn btn-sm">Corrigir linhas</a>
                        </div>
                    @endif
                    @if ($resumo['falhou'])
                        <div class="card border-warn-200 p-4 space-y-2">
                            <div class="font-semibold">{{ $f($resumo['falhou']) }} não {{ $resumo['falhou'] === 1 ? 'saiu' : 'saíram' }} por instabilidade</div>
                            <div class="text-[13px] text-ink-700 leading-snug">Tentamos várias vezes. Os dados estão certos: é só reenviar.</div>
                            @can('emitir')
                                <form method="POST" action="{{ route('importacoes.reenviar-falhas', $importacao) }}" data-ajax data-ajax-texto="Reenviando…">@csrf<button class="btn btn-primary btn-sm">Reenviar {{ $resumo['falhou'] === 1 ? 'a nota' : 'as '.$resumo['falhou'] }}</button></form>
                            @endcan
                        </div>
                    @endif
                </div>
            </section>
        @endif
        </div>

        <div class="flex flex-wrap gap-5 items-start">
            <section class="card flex-[999_1_620px] min-w-0 pt-4 pb-1.5" data-regiao="lista" data-versao="{{ $versao }}">
                <div class="px-5 sm:px-6 pb-3.5 flex flex-wrap gap-2">
                    @foreach ($filtros as $chave => [$rotulo, $qtd])
                        <a href="{{ route('importacoes.show', [$importacao, 'filtro' => $chave]) }}" @class(['inline-flex items-center gap-1.5 min-h-[40px] px-3.5 rounded-full border text-sm no-underline', 'bg-ink-900 text-white border-ink-900' => $filtro === $chave, 'bg-white text-ink-900 border-gray-300 hover:bg-gray-50' => $filtro !== $chave])>{{ $rotulo }} <span class="opacity-80">{{ $f($qtd) }}</span></a>
                    @endforeach
                </div>
                <div class="hidden sm:grid grid-cols-[48px_minmax(0,1.4fr)_100px_minmax(0,1.5fr)_auto] gap-x-4 px-6 py-2 text-xs font-medium text-ink-600 uppercase tracking-wide border-t border-linha">
                    <span>Linha</span><span>Cliente</span><span class="text-right">Valor</span><span>Situação</span><span></span>
                </div>
                @forelse ($notas as $n)
                    <x-nota-linha :nota="$n" :href="route('importacoes.show', [$importacao, 'filtro' => $filtro, 'nota' => $n->id])" :selecionada="$selecionada?->id === $n->id" />
                @empty
                    <div class="px-6 py-10 text-center tabela-linha text-ink-700">Nenhuma nota neste filtro.</div>
                @endforelse
                <div class="px-5 sm:px-6 py-3.5 tabela-linha">{{ $notas->links() }}</div>
            </section>

            <div id="painel-vaga" class="flex-[1_1_380px] min-w-0 max-w-[460px] empty:hidden">@if ($selecionada && $selecionada->status->grupo() === 'atencao')@include('notas._painel', ['nota' => $selecionada, 'fechar' => route('importacoes.show', [$importacao, 'filtro' => $filtro])])@endif</div>
        </div>
    </div>
</x-app-layout>
