<x-app-layout titulo="Créditos">
    @php
        $f = fn ($n) => number_format($n, 0, ',', '.');
        $aguardando = \App\Models\NotaFiscal::where('status', \App\Enums\StatusNota::AguardandoCredito)->count();
        $ativos = $pacotes->where('ativo', true)->sortBy('ordem')->values();
        $destaque = $ativos->count() >= 3 ? $ativos[1]->id : null;
    @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div>
            <h1 class="text-3xl font-semibold">Créditos</h1>
            <p class="mt-1 text-[15px] text-ink-700">1 crédito = 1 nota emitida. Nota recusada ou que falhou não consome.</p>
        </div>

        @if ($aguardando)
            <div class="card bg-amber-50 border-amber-200 p-5 sm:px-6">
                <b class="text-amber-900 text-base">{{ $aguardando }} {{ $aguardando === 1 ? 'nota está aguardando' : 'notas estão aguardando' }} crédito</b>
                <div class="text-sm text-amber-900 mt-0.5">Assim que o pagamento for confirmado, elas voltam para a fila sozinhas, na ordem em que foram enviadas.</div>
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="card p-5">
                <div class="text-sm text-ink-600">Disponíveis</div>
                <div class="font-mono text-4xl font-semibold mt-1 {{ $carteira->disponivel <= 0 ? 'text-warn-800' : '' }}">{{ $f($carteira->disponivel) }}</div>
                @if ($carteira->disponivel < 0)<p class="text-sm text-warn-800 mt-1">Saldo negativo (devolução de Pix). A emissão fica bloqueada até uma nova recarga.</p>@endif
            </div>
            <div class="card p-5"><div class="text-sm text-ink-600">Reservados (notas na fila)</div><div class="font-mono text-4xl font-semibold mt-1">{{ $f($carteira->reservado) }}</div></div>
            <div class="card p-5"><div class="text-sm text-ink-600">Aviso de saldo baixo</div><div class="mt-3 text-[15px]">abaixo de <b class="font-mono">{{ app(\App\Support\Tenancy\ContaAtual::class)->conta()->limite_aviso_saldo }}</b></div></div>
        </div>

        <section class="card p-5 sm:p-6">
            <h2 class="text-xl font-semibold">Escolha um pacote</h2>
            <p class="text-sm text-ink-600 mt-1 mb-5">Pagamento por Pix. Os créditos entram assim que o banco confirmar.</p>
            @if ($ativos->isEmpty())
                <p class="text-sm text-ink-700">Nenhum pacote disponível no momento.</p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach ($ativos as $p)
                        <div @class(['rounded-xl p-5 flex flex-col gap-1.5 relative', 'border-2 border-brand-600 bg-brand-50' => $p->id === $destaque, 'border border-gray-300' => $p->id !== $destaque])>
                            @if ($p->id === $destaque)<span class="pill absolute -top-3 left-4 bg-brand-600 text-white text-xs">Mais escolhido</span>@endif
                            <div class="font-semibold">{{ $p->nome }}</div>
                            <div class="font-mono text-3xl font-semibold">{{ $f($p->creditos) }}<span class="text-sm font-normal text-ink-600 font-sans"> notas</span></div>
                            <div class="text-lg font-semibold">R$ {{ number_format($p->preco_centavos / 100, 2, ',', '.') }}</div>
                            <div class="text-xs text-ink-600 mb-2">R$ {{ number_format($p->preco_centavos / 100 / max($p->creditos, 1), 2, ',', '.') }} por nota · validade {{ $p->validade_dias }} dias</div>
                            @if ($podeComprar)
                                <form method="POST" action="{{ route('creditos.comprar', $p) }}" class="mt-auto">@csrf<button @class(['btn w-full', 'btn-primary' => $p->id === $destaque])>Pagar com Pix</button></form>
                            @endif
                        </div>
                    @endforeach
                </div>
                @unless ($podeComprar)<p class="text-xs text-ink-600 mt-3">Só Dono e Financeiro podem comprar.</p>@endunless
            @endif
        </section>

        @php $temDescricao = $extrato->contains(fn ($m) => filled($m->descricao)); @endphp
        <section class="card pt-5 pb-1.5 overflow-x-auto">
            <h2 class="text-lg font-semibold px-5 sm:px-6 pb-3">Extrato</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-[13px] text-ink-600 border-t border-linha bg-chao/60"><tr>
                    <th class="px-5 sm:px-6 py-2.5 font-medium w-[150px]"><x-ordenavel col="data" :ordem="$ordem" :dir="$dir">Data</x-ordenavel></th><th class="py-2.5 font-medium"><x-ordenavel col="movimento" :ordem="$ordem" :dir="$dir">Movimento</x-ordenavel></th>
                    <th class="py-2.5 font-medium text-right w-[90px]"><x-ordenavel col="quantidade" :ordem="$ordem" :dir="$dir">Qtd.</x-ordenavel></th><th class="py-2.5 font-medium text-right w-[90px] {{ $temDescricao ? '' : 'pe-5 sm:pe-6' }}"><x-ordenavel col="saldo" :ordem="$ordem" :dir="$dir">Saldo</x-ordenavel></th>
                    @if ($temDescricao)<th class="ps-6 pe-5 sm:pe-6 py-2.5 font-medium">Descrição</th>@endif
                </tr></thead>
                <tbody>
                @forelse ($extrato as $m)
                    <tr class="tabela-linha">
                        <td class="px-5 sm:px-6 py-2.5 whitespace-nowrap font-mono text-[13px] text-ink-700">{{ $m->created_at->format('d/m H:i') }}</td>
                        <td class="py-2.5">{{ ucfirst($m->tipo->value) }}</td>
                        <td class="py-2.5 text-right font-mono {{ $m->quantidade < 0 ? 'text-warn-800' : 'text-ok-800' }}">{{ $m->quantidade > 0 ? '+' : '' }}{{ $m->quantidade }}</td>
                        <td class="py-2.5 text-right font-mono {{ $temDescricao ? '' : 'pe-5 sm:pe-6' }}">{{ $m->disponivel_apos }}</td>
                        @if ($temDescricao)<td class="ps-6 pe-5 sm:pe-6 py-2.5 text-ink-600">{{ $m->descricao }}</td>@endif
                    </tr>
                @empty
                    <tr class="tabela-linha"><td colspan="5" class="px-6 py-8 text-center text-ink-700">Sem movimentos ainda.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
        {{ $extrato->links() }}
    </div>
</x-app-layout>
