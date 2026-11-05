@props(['importacao', 'resumo', 'situacao', 'selo'])
@php
    $total = max(array_sum($resumo) - $resumo['rascunho'], 1);
    $emitidas = $resumo['emitida'];
    $atencao = $resumo['invalida'] + $resumo['rejeitada'] + $resumo['falhou'];
    $andamento = $resumo['pendente'] + $resumo['enviando'] + $resumo['aguardando_credito'];
    $pct = fn ($n) => round($n / $total * 100, 1);
    $aguardandoConferencia = in_array($importacao->status, ['pendente', 'lendo', 'conferir'], true);
@endphp
<a href="{{ $aguardandoConferencia ? route('importacoes.conferir', $importacao) : route('importacoes.show', $importacao) }}"
   class="grid grid-cols-1 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1.5fr)_auto] gap-2 sm:gap-4 items-center px-5 sm:px-6 py-4 tabela-linha text-inherit no-underline hover:bg-gray-50">
    <div>
        <div class="font-medium text-[15px]">{{ $importacao->arquivo_nome }}</div>
        <div class="text-[13px] text-ink-600">{{ $importacao->created_at->isToday() ? 'hoje, '.$importacao->created_at->format('H:i') : $importacao->created_at->format('d/m, H:i') }}@if ($importacao->total_linhas) · {{ $importacao->total_linhas }} notas @endif</div>
    </div>
    <div>
        @if ($importacao->status === 'falhou')
            <div class="text-[13px] text-warn-800">{{ $importacao->erro }}</div>
        @elseif ($aguardandoConferencia)
            <div class="text-[13px] text-ink-600">{{ $importacao->status === 'conferir' ? 'Falta conferir e confirmar o envio.' : 'Lendo e conferindo as linhas…' }}</div>
        @else
            <div class="h-2 rounded bg-gray-200 flex overflow-hidden" role="img" aria-label="{{ $emitidas }} emitidas, {{ $andamento }} em andamento, {{ $atencao }} precisam de você">
                <span class="bg-brand-600" style="width: {{ $pct($emitidas) }}%"></span>
                <span class="bg-info-500" style="width: {{ $pct($andamento) }}%"></span>
                <span class="bg-warn-600" style="width: {{ $pct($atencao) }}%"></span>
            </div>
            <div class="text-xs text-ink-600 mt-1.5">
                {{ $emitidas }} emitidas
                @if ($andamento) · {{ $andamento }} em andamento @endif
                @if ($atencao) · <b class="text-warn-800">{{ $atencao }} {{ $atencao === 1 ? 'precisa' : 'precisam' }} de você</b> @endif
            </div>
        @endif
    </div>
    <span class="pill {{ $selo }} justify-self-start sm:justify-self-end">{{ $situacao }}</span>
</a>
