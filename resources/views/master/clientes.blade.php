<x-app-layout titulo="Master · Clientes">
    @php $f = fn ($n) => number_format($n, 0, ',', '.'); @endphp

    <div class="max-w-[1240px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-3xl font-semibold">Clientes</h1>
                <span class="pill bg-info-100 text-info-800">Master · somente leitura</span>
            </div>
            <p class="mt-1 text-[15px] text-ink-700">{{ $f($contas->total()) }} {{ $contas->total() === 1 ? 'conta' : 'contas' }}. Clique em uma para ver empresas, usuários, créditos e erros.</p>
        </div>

        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[220px]">
                <label for="q" class="campo-rotulo">Buscar</label>
                <input id="q" name="q" value="{{ $busca }}" placeholder="Nome, CNPJ/CPF ou e-mail" class="campo-entrada" autocomplete="off">
            </div>
            <div>
                <label for="situacao" class="campo-rotulo">Situação</label>
                <select id="situacao" name="situacao" class="campo-entrada">
                    <option value="">Todas</option>
                    @foreach (['ativa' => 'Ativa', 'suspensa' => 'Suspensa', 'bloqueada_saldo_negativo' => 'Bloqueada (saldo negativo)'] as $v => $r)
                        <option value="{{ $v }}" @selected($situacao === $v)>{{ $r }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="ordem" class="campo-rotulo">Ordenar por</label>
                <select id="ordem" name="ordem" class="campo-entrada">
                    @foreach (['atividade' => 'Atividade recente', 'emitidas' => 'Mais notas no mês', 'recusas' => 'Mais recusas', 'saldo' => 'Menor saldo', 'nome' => 'Nome'] as $v => $r)
                        <option value="{{ $v }}" @selected($ordem === $v)>{{ $r }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-primary">Filtrar</button>
        </form>

        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-ink-600 bg-chao/60 border-b border-linha">
                    <tr>
                        <th class="px-5 py-3 font-medium">Cliente</th>
                        <th class="px-3 py-3 font-medium">Empresas</th>
                        <th class="px-3 py-3 font-medium text-right">Créditos</th>
                        <th class="px-3 py-3 font-medium text-right">Emitidas no mês</th>
                        <th class="px-3 py-3 font-medium text-right">Recusadas (30d)</th>
                        <th class="px-5 py-3 font-medium">Última atividade</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($contas as $c)
                    @php
                        $certMin = $c->emitentes->whereNotNull('certificado_validade')->min('certificado_validade');
                        $certVencido = $certMin && \Illuminate\Support\Carbon::parse($certMin)->isPast();
                        $certVencendo = $certMin && ! $certVencido && \Illuminate\Support\Carbon::parse($certMin)->lt(now()->addDays(30));
                        $producao = $c->emitentes->where('ambiente', 1)->count();
                    @endphp
                    <tr class="tabela-linha hover:bg-gray-50">
                        <td class="px-5 py-3">
                            <a href="{{ route('master.cliente', $c) }}" class="font-medium text-ink-900 no-underline hover:underline">{{ $c->nome }}</a>
                            <div class="text-xs text-ink-600">{{ $c->email_financeiro }} · {{ $f($c->usuarios_count) }} {{ $c->usuarios_count === 1 ? 'usuário' : 'usuários' }}
                                @if ($c->status !== 'ativa')<span class="pill bg-warn-100 text-warn-800 ms-1">{{ $c->status }}</span>@endif</div>
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">
                            {{ $f($c->emitentes_count) }}
                            @if ($c->emitentes_count)<span class="text-xs text-ink-600">· {{ $producao ? $producao.' em produção' : 'em teste' }}</span>@endif
                            @if ($certVencido)<div class="text-xs text-warn-800 font-medium">certificado vencido</div>
                            @elseif ($certVencendo)<div class="text-xs text-amber-900 font-medium">certificado vence {{ \Illuminate\Support\Carbon::parse($certMin)->format('d/m') }}</div>@endif
                        </td>
                        <td class="px-3 py-3 text-right font-mono {{ ($c->saldo ?? 0) <= 0 ? 'text-warn-800' : '' }}">{{ $f($c->saldo ?? 0) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ $f($c->emitidas_mes) }}</td>
                        <td class="px-3 py-3 text-right font-mono {{ $c->recusadas_30d >= 3 ? 'text-warn-800 font-semibold' : '' }}">{{ $f($c->recusadas_30d) }}</td>
                        <td class="px-5 py-3 whitespace-nowrap text-ink-700">{{ $c->ultima_atividade ? \Illuminate\Support\Carbon::parse($c->ultima_atividade)->diffForHumans() : '—' }}</td>
                    </tr>
                @empty
                    <tr class="tabela-linha"><td colspan="6" class="px-5 py-10 text-center text-ink-700">Nenhum cliente encontrado.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $contas->links() }}
    </div>
</x-app-layout>
