@php
    $usuario    = Auth::user();
    $contaAtiva = app(\App\Support\Tenancy\ContaAtual::class)->conta();
    $saldo      = $contaAtiva ? \App\Models\Carteira::where('conta_id', $contaAtiva->id)->value('disponivel') : null;

    // Selo de ambiente sempre visível: o medo de emitir "de verdade" sem querer é real.
    $ambientes  = $contaAtiva ? \App\Models\Emitente::where('ativo', true)->pluck('ambiente') : collect();
    $modoTeste  = $ambientes->isNotEmpty() && $ambientes->contains(2);
    $emProducao = $ambientes->isNotEmpty() && ! $modoTeste;

    $links = $contaAtiva ? array_filter([
        ['inicio',            'Início',       'consultar', 'inicio'],
        ['importacoes.index', 'Importações',  'consultar', 'importacoes.*'],
        ['creditos.index',    'Créditos',     'consultar', 'creditos.*'],
    ], fn ($l) => Gate::allows($l[2])) : [];
@endphp
<nav x-data="{ open: false }" class="bg-white border-b border-linha" aria-label="Principal">
    <div class="max-w-[1240px] mx-auto px-4 sm:px-8">
        <div class="flex items-center justify-between min-h-16 gap-4">
            <div class="flex items-center gap-2 sm:gap-4">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-lg bg-brand-600 text-white flex items-center justify-center font-semibold" aria-hidden="true">N</span>
                    <span class="font-semibold hidden sm:inline">{{ config('saas.nome_produto', 'Emissor NFS-e') }}</span>
                </a>

                <div class="hidden md:flex gap-1 ms-4">
                    @foreach ($links as [$rota, $rotulo, , $ativo])
                        <a href="{{ route($rota) }}" @class(['inline-flex items-center min-h-[44px] px-3.5 rounded-lg text-[15px] font-medium', 'bg-gray-100 text-ink-900' => request()->routeIs($ativo), 'text-ink-700 hover:bg-gray-50' => ! request()->routeIs($ativo)]) @if(request()->routeIs($ativo)) aria-current="page" @endif>{{ $rotulo }}</a>
                    @endforeach
                </div>
            </div>

            <div class="hidden md:flex items-center gap-3">
                @if ($modoTeste)
                    <span class="pill bg-amber-100 text-amber-900 font-semibold" title="As notas emitidas em modo teste não têm valor fiscal">Modo teste</span>
                @elseif ($emProducao)
                    <span class="pill bg-ok-100 text-ok-800 font-semibold">Produção</span>
                @endif

                @if ($contaAtiva)
                    <a href="{{ route('creditos.index') }}" class="inline-flex items-center gap-2 min-h-[44px] px-3.5 border border-gray-300 rounded-lg text-sm text-ink-900 hover:bg-gray-50" title="Créditos disponíveis">
                        <b class="font-mono {{ ($saldo ?? 0) <= 0 ? 'text-warn-800' : '' }}">{{ number_format($saldo ?? 0, 0, ',', '.') }}</b> créditos
                        <span class="text-brand-600 font-semibold">+ Recarregar</span>
                    </a>
                @endif

                <x-dropdown align="right" width="64">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center gap-2 min-h-[44px] px-2 text-sm text-ink-700 rounded-lg hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                            <span class="text-left leading-tight">
                                <span class="block font-medium text-ink-900">{{ $contaAtiva?->nome ?? $usuario->name }}</span>
                                <span class="block text-xs text-ink-600">{{ $usuario->name }}</span>
                            </span>
                            <svg class="h-4 w-4 fill-current" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @if ($contaAtiva)
                            <x-dropdown-link :href="route('emitentes.index')">Empresas que emitem</x-dropdown-link>
                            @can('gerenciar-usuarios')<x-dropdown-link :href="route('usuarios.index')">Usuários da conta</x-dropdown-link>@endcan
                            <div class="border-t border-gray-100 my-1"></div>
                        @endif
                        @foreach ($usuario->contas as $c)
                            @if (! $contaAtiva || $c->id !== $contaAtiva->id)
                                <form method="POST" action="{{ route('contas.trocar', $c->id) }}">
                                    @csrf
                                    <x-dropdown-link href="#" onclick="event.preventDefault(); this.closest('form').submit();">Trocar para {{ $c->nome }}</x-dropdown-link>
                                </form>
                            @endif
                        @endforeach
                        <x-dropdown-link :href="route('contas.criar')">Criar outra conta</x-dropdown-link>
                        <x-dropdown-link :href="route('profile.edit')">Meu perfil</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Sair</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="flex items-center md:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center w-11 h-11 rounded-lg text-ink-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Abrir menu" :aria-expanded="open">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden md:hidden border-t border-linha">
        <div class="px-4 py-3 space-y-1">
            @foreach ($links as [$rota, $rotulo, , $ativo])
                <a href="{{ route($rota) }}" @class(['flex items-center min-h-[44px] px-3 rounded-lg font-medium', 'bg-gray-100' => request()->routeIs($ativo)])>{{ $rotulo }}</a>
            @endforeach
            @if ($contaAtiva)
                <a href="{{ route('emitentes.index') }}" class="flex items-center min-h-[44px] px-3 rounded-lg text-ink-700">Empresas que emitem</a>
                @can('gerenciar-usuarios')<a href="{{ route('usuarios.index') }}" class="flex items-center min-h-[44px] px-3 rounded-lg text-ink-700">Usuários da conta</a>@endcan
            @endif
            <a href="{{ route('profile.edit') }}" class="flex items-center min-h-[44px] px-3 rounded-lg text-ink-700">Meu perfil</a>
        </div>
        <div class="px-4 py-3 border-t border-linha flex items-center justify-between gap-3 text-sm">
            <div>
                <div class="font-medium">{{ $contaAtiva?->nome ?? $usuario->name }}</div>
                @if ($contaAtiva)<div class="text-ink-600"><b class="font-mono">{{ number_format($saldo ?? 0, 0, ',', '.') }}</b> créditos @if ($modoTeste)· modo teste @endif</div>@endif
            </div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-sm">Sair</button></form>
        </div>
    </div>
</nav>
