@props(['importacao', 'resumo', 'situacao', 'selo'])
@php
    $total = max(array_sum($resumo) - $resumo['rascunho'], 1);
    $emitidas = $resumo['emitida'];
    $atencao = $resumo['invalida'] + $resumo['rejeitada'] + $resumo['falhou'];
    $andamento = $resumo['pendente'] + $resumo['enviando'] + $resumo['aguardando_credito'];
    $pct = fn ($n) => round($n / $total * 100, 1);
    $aguardandoConferencia = in_array($importacao->status, ['pendente', 'lendo', 'conferir'], true);
    // Só dá para excluir o que não emitiu nada e não está na fila (créditos já devolvidos)
    $podeExcluir = $importacao->status !== 'lendo' && ($resumo['emitida'] + $resumo['pendente'] + $resumo['enviando'] + $resumo['aguardando_credito']) === 0;
@endphp
<div class="flex items-center tabela-linha hover:bg-gray-50" data-item="lote-{{ $importacao->id }}">
<a href="{{ $aguardandoConferencia ? route('importacoes.conferir', $importacao) : route('importacoes.show', $importacao) }}"
   class="flex-1 min-w-0 grid grid-cols-1 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1.4fr)_172px] gap-2 sm:gap-4 items-center ps-5 sm:ps-6 pe-3 py-4 text-inherit no-underline">
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
@can('emitir')
    <div class="shrink-0 pe-3 sm:pe-4 w-[112px] flex justify-end">
        @if ($podeExcluir)
            <button type="button" class="btn-excluir" data-acao-url="{{ route('importacoes.excluir', $importacao) }}" data-confirmar="Excluir mesmo?" data-saindo="self" aria-label="Excluir a importação {{ $importacao->arquivo_nome }}" title="Excluir esta importação">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>Excluir
            </button>
        @endif
    </div>
@endcan
</div>
