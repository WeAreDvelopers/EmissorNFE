@php
    $ex = \App\Support\ExplicacaoErro::para($nota);
    $mesmoCodigo = in_array('codigo_servico', $ex['campos'], true) && $nota->importacao_id
        ? \App\Models\NotaFiscal::where('importacao_id', $nota->importacao_id)->where('status', \App\Enums\StatusNota::Rejeitada)->where('codigo_servico', $nota->codigo_servico)->whereKeyNot($nota->id)->count()
        : 0;
@endphp
<aside class="card w-full p-5 sm:p-6 space-y-4 border-2 border-brand-600" aria-label="Corrigir nota da linha {{ $nota->linha_planilha }}">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-[13px] text-ink-600">Linha {{ $nota->linha_planilha }} · {{ $nota->tomador_rotulo }}</div>
            <h2 class="text-xl font-semibold mt-0.5">{{ $ex['titulo'] }}</h2>
        </div>
        <a href="{{ $fechar }}" data-fechar aria-label="Fechar" class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-300 hover:bg-gray-50">
            <svg class="w-4 h-4 text-ink-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </a>
    </div>

    <div class="rounded-lg bg-warn-50 p-3.5 text-sm leading-relaxed text-warn-900">
        <b>O que aconteceu:</b> {{ $ex['o_que_aconteceu'] }}
        <div class="mt-2"><b>Como resolver:</b> {{ $ex['como_resolver'] }}</div>
    </div>

    @can('emitir')
        @if ($ex['reenviar_direto'])
            <form method="POST" action="{{ route('notas.reenviar', $nota) }}" data-ajax data-ajax-texto="Reenviando…" data-fechar-painel>@csrf<button class="btn btn-primary btn-lg w-full">Reenviar</button></form>
        @elseif ($ex['campos'])
            <form method="POST" action="{{ route('notas.corrigir', $nota) }}" class="space-y-4" data-ajax data-ajax-texto="{{ $nota->importacao?->status === 'conferir' ? 'Salvando…' : 'Reenviando…' }}" data-fechar-painel>
                @csrf @method('PATCH')
                @foreach ($ex['campos'] as $campo)
                    @php $valorAtual = match ($campo) {
                        'data_competencia' => $nota->data_competencia?->format('d/m/Y'),
                        'valor' => $nota->valor > 0 ? number_format($nota->valor, 2, ',', '') : '',
                        default => $nota->{$campo},
                    }; @endphp
                    <div>
                        <label for="f-{{ $campo }}" class="campo-rotulo">{{ \App\Support\ExplicacaoErro::ROTULOS[$campo] }}</label>
                        @if ($campo === 'descricao')
                            <textarea id="f-{{ $campo }}" name="{{ $campo }}" rows="3" class="campo-entrada">{{ old($campo, $valorAtual) }}</textarea>
                        @else
                            <input id="f-{{ $campo }}" name="{{ $campo }}" value="{{ old($campo, $valorAtual) }}" class="campo-entrada font-mono">
                        @endif
                        @if ($campo === 'codigo_servico' && $mesmoCodigo)
                            <label class="mt-2 flex items-center gap-2 text-[13px] text-ink-700"><input type="checkbox" name="aplicar_em_todas" value="1" class="rounded text-brand-600 focus:ring-brand-600"> Aplicar também nas outras {{ $mesmoCodigo }} {{ $mesmoCodigo === 1 ? 'nota recusada' : 'notas recusadas' }} com este código</label>
                        @endif
                    </div>
                @endforeach

                <div class="rounded-lg bg-chao px-3.5 py-3 text-[13px] leading-relaxed text-ink-700">
                    @if ($nota->status === \App\Enums\StatusNota::Invalida && $nota->importacao?->status === 'conferir')
                        Esta nota ainda não gastou crédito.
                    @elseif ($nota->emitente?->semCobranca())
                        {{ $nota->emitente->ehHomologacao() ? 'Modo teste: reenviar não gasta crédito.' : 'Esta empresa não paga por nota: reenviar não gasta crédito.' }}
                    @else
                        Esta nota não gastou crédito. Ao reenviar, 1 crédito é reservado de novo e só é cobrado se a prefeitura autorizar.
                    @endif
                </div>
                <button class="btn btn-primary btn-lg w-full">{{ $nota->importacao?->status === 'conferir' ? 'Confirmar correção' : 'Corrigir e reenviar' }}</button>
            </form>
        @endif
    @endcan

    @if ($ex['tecnico'])
        <details>
            <summary class="cursor-pointer text-sm text-ink-700 min-h-8">Detalhes técnicos</summary>
            <div class="font-mono text-xs leading-relaxed text-ink-700 pt-2 break-words space-y-1">
                @foreach ($ex['tecnico'] as $linha)<div>{{ $linha }}</div>@endforeach
                @if ($nota->numero_dps)<div>DPS série {{ $nota->serie_dps }} · nº {{ $nota->numero_dps }}</div>@endif
                <div>Tentativas: {{ $nota->tentativas }}</div>
            </div>
        </details>
    @endif
</aside>
