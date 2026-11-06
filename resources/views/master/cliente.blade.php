<x-app-layout :titulo="'Master · '.$conta->nome">
    @php
        $f = fn ($n) => number_format($n, 0, ',', '.');
        $statusRotulo = ['emitida' => 'Emitidas', 'rejeitada' => 'Recusadas', 'falhou' => 'Falha técnica', 'invalida' => 'Dado inválido', 'rascunho' => 'Rascunho', 'pendente' => 'Na fila', 'enviando' => 'Enviando', 'aguardando_credito' => 'Aguardando crédito'];
    @endphp

    <div class="max-w-[1240px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-2">
            <a href="{{ route('master.clientes') }}" class="link text-sm">← Clientes</a>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-semibold">{{ $conta->nome }}</h1>
                <span class="pill {{ $conta->status === 'ativa' ? 'bg-ok-100 text-ok-800' : 'bg-warn-100 text-warn-800' }}">{{ $conta->status }}</span>
                <span class="pill bg-info-100 text-info-800">Master · somente leitura</span>
            </div>
            <p class="text-[15px] text-ink-700">{{ $conta->documento }} · {{ $conta->email_financeiro }} · cliente desde {{ $conta->created_at?->format('d/m/Y') }}@if ($conta->revenda) · revenda {{ $conta->revenda->nome ?? $conta->revenda->id }}@endif</p>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="card p-5"><div class="text-sm text-ink-600">Créditos disponíveis</div><div class="text-4xl font-semibold font-mono mt-1 {{ ($carteira->disponivel ?? 0) <= 0 ? 'text-warn-800' : '' }}">{{ $f($carteira->disponivel ?? 0) }}</div><div class="text-[13px] text-ink-600 mt-1">{{ $f($carteira->reservado ?? 0) }} reservados</div></div>
            <div class="card p-5"><div class="text-sm text-ink-600">Emitidas no mês</div><div class="text-4xl font-semibold font-mono text-ok-800 mt-1">{{ $f($emitidasMes) }}</div><div class="text-[13px] text-ink-600 mt-1">R$ {{ number_format($valorMes, 2, ',', '.') }}</div></div>
            <div class="card p-5"><div class="text-sm text-ink-600">Recusadas</div><div class="text-4xl font-semibold font-mono mt-1">{{ $f($porStatus['rejeitada'] ?? 0) }}</div><div class="text-[13px] text-ink-600 mt-1">{{ $f($porStatus['falhou'] ?? 0) }} com falha técnica</div></div>
            <div class="card p-5"><div class="text-sm text-ink-600">Na fila</div><div class="text-4xl font-semibold font-mono mt-1">{{ $f(($porStatus['pendente'] ?? 0) + ($porStatus['enviando'] ?? 0)) }}</div><div class="text-[13px] text-ink-600 mt-1">{{ $f($porStatus['aguardando_credito'] ?? 0) }} aguardando crédito</div></div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Empresas que emitem</h2>
                @forelse ($emitentes as $e)
                    @php $venc = $e->certificado_validade; @endphp
                    <div class="tabela-linha py-3">
                        <div class="font-medium text-sm">{{ $e->razao_social }}</div>
                        <div class="text-xs text-ink-600 font-mono">{{ \App\Support\Documento::formatar($e->cnpj) }} · município {{ $e->codigo_municipio }}@if ($e->codigo_servico_padrao) · serviço padrão {{ $e->codigo_servico_padrao }}@endif</div>
                        <div class="mt-1 flex flex-wrap gap-2 text-xs">
                            <span class="pill {{ $e->ehHomologacao() ? 'bg-amber-100 text-amber-900' : 'bg-ok-100 text-ok-800' }}">{{ $e->ehHomologacao() ? 'Teste' : 'Produção' }}</span>
                            @if (! $venc)<span class="pill bg-warn-100 text-warn-800">sem certificado</span>
                            @elseif ($venc->isPast())<span class="pill bg-warn-100 text-warn-800">certificado vencido em {{ $venc->format('d/m/Y') }}</span>
                            @elseif ($venc->lt(now()->addDays(30)))<span class="pill bg-amber-100 text-amber-900">certificado vence {{ $venc->format('d/m/Y') }}</span>
                            @else<span class="pill bg-gray-100 text-ink-700">certificado até {{ $venc->format('d/m/Y') }}</span>@endif
                            <span class="pill {{ $e->cobranca_desligada ? 'bg-info-100 text-info-800' : 'bg-gray-100 text-ink-700' }}">{{ $e->cobranca_desligada ? 'sem cobrança' : 'cobrança normal' }}</span>
                        </div>
                        <form method="POST" action="{{ route('master.empresa.cobranca', $e->id) }}" class="mt-2">
                            @csrf
                            <input type="hidden" name="desligar" value="{{ $e->cobranca_desligada ? 0 : 1 }}">
                            <button class="btn btn-secondary text-xs">{{ $e->cobranca_desligada ? 'Religar cobrança' : 'Desligar cobrança' }}</button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-ink-600 mt-2">Nenhuma empresa cadastrada.</p>
                @endforelse
            </section>

            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Usuários</h2>
                @foreach ($usuarios as $u)
                    <div class="tabela-linha py-3 flex justify-between gap-3 text-sm">
                        <div class="min-w-0"><div class="font-medium truncate">{{ $u->name }}</div><div class="text-xs text-ink-600 truncate">{{ $u->email }}</div></div>
                        <span class="pill bg-gray-100 text-ink-700 self-center">{{ $u->pivot->papel }}</span>
                    </div>
                @endforeach
            </section>
        </div>

        <section class="card p-5 sm:p-6">
            <h2 class="text-lg font-semibold">Notas por situação</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($porStatus as $status => $total)
                    <span class="pill bg-gray-100 text-ink-900">{{ $statusRotulo[$status] ?? $status }} <b class="font-mono">{{ $f($total) }}</b></span>
                @empty
                    <span class="text-sm text-ink-600">Nenhuma nota ainda.</span>
                @endforelse
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Últimos erros</h2>
                @forelse ($ultimosErros as $n)
                    <div class="tabela-linha py-3 text-sm">
                        <div class="flex justify-between gap-3"><span class="font-medium truncate">Linha {{ $n->linha_planilha }} · {{ $n->tomador_rotulo }}</span><span class="text-xs text-ink-600 shrink-0">{{ $n->updated_at->format('d/m H:i') }}</span></div>
                        <div class="text-[13px] text-warn-800 leading-snug break-words">{{ \Illuminate\Support\Str::limit(implode(' | ', (array) $n->erros), 200) }}</div>
                        <div class="text-xs text-ink-600 font-mono">serviço {{ $n->codigo_servico }} · {{ $n->status->label() }}</div>
                    </div>
                @empty
                    <p class="text-sm text-ink-600 mt-2">Nenhum erro recente.</p>
                @endforelse
            </section>

            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Importações recentes</h2>
                @forelse ($importacoes as $i)
                    <div class="tabela-linha py-3 flex justify-between gap-3 text-sm">
                        <div class="min-w-0"><div class="font-medium truncate">{{ $i->arquivo_nome }}</div><div class="text-xs text-ink-600">{{ $i->emitente?->razao_social }} · {{ $i->total_linhas }} linhas</div></div>
                        <div class="text-right shrink-0"><div class="text-xs">{{ $i->status }}</div><div class="text-xs text-ink-600">{{ $i->created_at->format('d/m H:i') }}</div></div>
                    </div>
                @empty
                    <p class="text-sm text-ink-600 mt-2">Nenhuma importação.</p>
                @endforelse
            </section>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Extrato de créditos <span class="text-sm font-normal text-ink-600">· últimos 12</span></h2>
                @forelse ($movimentos as $m)
                    <div class="tabela-linha py-2 flex justify-between gap-3 text-sm">
                        <span>{{ $m->tipo->value }} <span class="text-xs text-ink-600">· {{ $m->created_at->format('d/m H:i') }}</span></span>
                        <span class="font-mono {{ $m->quantidade < 0 ? 'text-warn-800' : 'text-ok-800' }}">{{ $m->quantidade > 0 ? '+' : '' }}{{ $m->quantidade }}</span>
                    </div>
                @empty
                    <p class="text-sm text-ink-600 mt-2">Sem movimentos.</p>
                @endforelse
            </section>

            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Compras de créditos</h2>
                @forelse ($pagamentos as $p)
                    <div class="tabela-linha py-2 flex justify-between gap-3 text-sm">
                        <span>{{ $f($p->creditos) }} créditos <span class="text-xs text-ink-600">· {{ $p->pago_em?->format('d/m/Y H:i') }}</span></span>
                        <span class="font-mono">R$ {{ number_format($p->valor_centavos / 100, 2, ',', '.') }}</span>
                    </div>
                @empty
                    <p class="text-sm text-ink-600 mt-2">Nenhuma compra paga.</p>
                @endforelse
            </section>
        </div>
    </div>
</x-app-layout>
