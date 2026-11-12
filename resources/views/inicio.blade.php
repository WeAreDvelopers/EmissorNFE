<x-app-layout titulo="Início">
    @php $atencao = $recusadas + $invalidas + $falhas; @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-7">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Início</h1>
                <p class="mt-1 text-[15px] text-ink-700">O que está acontecendo com as suas notas e o que precisa de você.</p>
            </div>
            @can('emitir')
                @if ($temEmitente)
                    <a href="{{ route('importacoes.criar') }}" class="btn btn-primary btn-lg">
                        <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg>
                        Importar planilha
                    </a>
                @endif
            @endcan
        </div>

        @unless ($temEmitente)
            <div class="card p-6 border-2 border-brand-600">
                <h2 class="text-lg font-semibold">Falta só cadastrar a empresa que vai emitir as notas</h2>
                <p class="mt-1 text-[15px] text-ink-700">Você envia o certificado digital e a gente preenche o resto. Leva uns 5 minutos e é uma vez só.</p>
                @can('gerenciar-emitentes')<a href="{{ route('emitentes.criar') }}" class="btn btn-primary mt-4">Cadastrar empresa</a>@endcan
            </div>
        @endunless

        @if ($atencao > 0)
            <div class="card bg-warn-50 border-warn-200 p-5 sm:px-6 flex flex-wrap items-center gap-5">
                <span class="w-10 h-10 rounded-full bg-warn-100 flex items-center justify-center shrink-0" aria-hidden="true"><svg class="w-5 h-5 text-warn-800" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 17h.01"/></svg></span>
                <div class="flex-1 min-w-[240px]">
                    <div class="text-lg font-semibold text-warn-900">{{ $atencao }} {{ $atencao === 1 ? 'nota precisa' : 'notas precisam' }} de você</div>
                    <div class="text-sm text-warn-800 mt-0.5">@cobranca Nenhuma delas gastou crédito. @endcobranca Resolva por grupo, em poucos cliques.</div>
                </div>
                <a href="{{ route('pendencias') }}" class="btn btn-atencao">Resolver agora</a>
            </div>
        @endif

        @if ($aguardandoCredito > 0 && ! app(\App\Support\Tenancy\EmitenteAtual::class)->semCobranca())
            <div class="card bg-amber-50 border-amber-200 p-5 sm:px-6 flex flex-wrap items-center gap-5">
                <div class="flex-1 min-w-[240px]">
                    <div class="text-lg font-semibold text-amber-900">{{ $aguardandoCredito }} {{ $aguardandoCredito === 1 ? 'nota está aguardando' : 'notas estão aguardando' }} crédito</div>
                    <div class="text-sm text-amber-900 mt-0.5">Assim que você recarregar, elas voltam para a fila sozinhas.</div>
                </div>
                @can('comprar-creditos')<a href="{{ route('creditos.index') }}" class="btn btn-primary">Recarregar créditos</a>@endcan
            </div>
        @endif

        @php $semCreditos = app(\App\Support\Tenancy\EmitenteAtual::class)->semCobranca(); @endphp
        <div class="grid grid-cols-1 {{ $semCreditos ? 'sm:grid-cols-2' : 'sm:grid-cols-3' }} gap-4">
            @unless ($semCreditos)
            <div class="card p-5">
                <div class="text-sm text-ink-600">Créditos disponíveis</div>
                <div class="text-4xl font-semibold font-mono mt-1 {{ $carteira->disponivel <= 0 ? 'text-warn-800' : '' }}">{{ number_format($carteira->disponivel, 0, ',', '.') }}</div>
                <div class="text-[13px] text-ink-600 mt-1">{{ number_format($carteira->reservado, 0, ',', '.') }} reservados para notas na fila · 1 crédito por nota emitida</div>
            </div>
            @endunless
            <div class="card p-5">
                <div class="text-sm text-ink-600">Emitidas neste mês</div>
                <div class="text-4xl font-semibold font-mono mt-1 text-ok-800">{{ number_format($emitidasMes, 0, ',', '.') }}</div>
                <div class="text-[13px] text-ink-600 mt-1">{{ now()->translatedFormat('F \d\e Y') }}</div>
            </div>
            <div class="card p-5">
                <div class="text-sm text-ink-600">Em andamento agora</div>
                <div class="text-4xl font-semibold font-mono mt-1">{{ number_format($andamento, 0, ',', '.') }}</div>
                <div class="text-[13px] text-ink-600 mt-1">{{ $andamento ? 'Você não precisa fazer nada.' : 'Nada na fila.' }}</div>
            </div>
        </div>

        <div class="flex flex-wrap gap-6 items-start">

            <section class="card flex-[999_1_560px] min-w-0 pt-5 pb-1">
                <div class="flex items-center justify-between px-5 sm:px-6 pb-3">
                    <h2 class="text-lg font-semibold">Últimas importações</h2>
                    <a href="{{ route('importacoes.index') }}" class="link text-sm">Ver todas</a>
                </div>
                @forelse ($recentes as $i)
                    @php $r = $resumos[$i->id] ?? array_fill_keys(array_map(fn ($s) => $s->value, \App\Enums\StatusNota::cases()), 0); [$sit, $selo] = $i->situacao($r); @endphp
                    <x-lote-linha :importacao="$i" :resumo="$r" :situacao="$sit" :selo="$selo" />
                @empty
                    <div class="px-6 py-10 text-center border-t border-gray-200">
                        <p class="text-ink-700">Você ainda não importou nenhuma planilha.</p>
                        @if ($temEmitente)<a href="{{ route('importacoes.criar') }}" class="btn btn-primary mt-4">Importar a primeira planilha</a>@endif
                    </div>
                @endforelse
            </section>

            @if ($atencao > 0)
                <section class="card flex-[1_1_320px] min-w-0 p-5 sm:p-6">
                    <h2 class="text-lg font-semibold">Precisa de você</h2>
                    <p class="text-[13px] text-ink-600 mb-4">Agrupado por causa. Uma ação resolve o grupo todo.</p>
                    <div class="space-y-3">
                        @if ($recusadas)
                            <div class="border border-gray-200 rounded-lg p-3.5">
                                <div class="flex justify-between gap-2"><b>{{ $recusadas }} recusadas pela prefeitura</b><span class="pill bg-warn-100 text-warn-800">{{ $recusadas }}</span></div>
                                <p class="text-[13px] text-ink-700 mt-1 mb-2.5 leading-snug">A prefeitura não aceitou algum dado. Mostramos qual e como corrigir.</p>
                                <a href="{{ route('pendencias', ['grupo' => 'recusadas']) }}" class="btn btn-sm">Ver e corrigir</a>
                            </div>
                        @endif
                        @if ($invalidas)
                            <div class="border border-gray-200 rounded-lg p-3.5">
                                <div class="flex justify-between gap-2"><b>{{ $invalidas }} {{ $invalidas === 1 ? 'linha com dado inválido' : 'linhas com dado inválido' }}</b><span class="pill bg-warn-100 text-warn-800">{{ $invalidas }}</span></div>
                                <p class="text-[13px] text-ink-700 mt-1 mb-2.5 leading-snug">CPF/CNPJ, valor ou data com problema. Dá para corrigir na tela.</p>
                                <a href="{{ route('pendencias', ['grupo' => 'invalidas']) }}" class="btn btn-sm">Corrigir linhas</a>
                            </div>
                        @endif
                        @if ($falhas)
                            <div class="border border-gray-200 rounded-lg p-3.5">
                                <div class="flex justify-between gap-2"><b>{{ $falhas }} não {{ $falhas === 1 ? 'saiu' : 'saíram' }} por instabilidade</b><span class="pill bg-warn-100 text-warn-800">{{ $falhas }}</span></div>
                                <p class="text-[13px] text-ink-700 mt-1 mb-2.5 leading-snug">A prefeitura não respondeu. Seus dados estão certos.</p>
                                <a href="{{ route('pendencias', ['grupo' => 'instabilidade']) }}" class="btn btn-primary btn-sm">Ver e reenviar</a>
                            </div>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
