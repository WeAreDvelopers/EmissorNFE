@props(['nota', 'href', 'selecionada' => false, 'mostrarLote' => false])
@php $precisa = $nota->status->grupo() === 'atencao'; @endphp
<div @class(['grid grid-cols-[44px_minmax(0,1fr)] sm:grid-cols-[48px_minmax(0,1.4fr)_100px_minmax(0,1.5fr)_auto] gap-x-4 gap-y-1 items-center px-5 sm:px-6 py-3 tabela-linha text-sm', 'bg-warn-50' => $selecionada])>
    <span class="font-mono text-ink-600">{{ $nota->linha_planilha }}</span>
    <div class="min-w-0">
        <div class="font-medium truncate">{{ $nota->tomador_nome }}</div>
        <div class="font-mono text-xs text-ink-600">{{ \App\Support\Documento::formatar($nota->tomador_documento) }}@if ($mostrarLote && $nota->importacao) · {{ $nota->importacao->arquivo_nome }}@endif</div>
    </div>
    <span class="font-mono sm:text-right col-start-2 sm:col-start-auto">R$ {{ number_format($nota->valor, 2, ',', '.') }}</span>
    <div class="col-start-2 sm:col-start-auto">
        <span class="pill {{ $nota->status->selo() }}">{{ $nota->status->label() }}</span>
        @if ($nota->status === \App\Enums\StatusNota::Emitida && $nota->numero_nfse)<div class="text-xs text-ink-600 mt-1">NFS-e nº {{ $nota->numero_nfse }}</div>@endif
        @if ($nota->status === \App\Enums\StatusNota::Enviando)<div class="text-xs text-ink-600 mt-1">Tentativa {{ max($nota->tentativas, 1) }}</div>@endif
    </div>
    <div class="col-start-2 sm:col-start-auto sm:text-right">
        @if ($precisa)
            <a href="{{ $href }}" class="font-semibold text-brand-600 no-underline hover:underline">{{ $nota->status === \App\Enums\StatusNota::Falhou ? 'Reenviar' : 'Corrigir' }} →</a>
        @endif
    </div>
</div>
