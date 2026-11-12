@php
    $usuario    = Auth::user();
    $contaAtiva = app(\App\Support\Tenancy\ContaAtual::class)->conta();
    $saldo      = $contaAtiva ? \App\Models\Carteira::where('conta_id', $contaAtiva->id)->value('disponivel') : null;

    // Selo de ambiente sempre visível: o medo de emitir "de verdade" sem querer é real.
    $emitentesAtivos = $contaAtiva ? \App\Models\Emitente::where('ativo', true)->get(['ambiente', 'cobranca_desligada']) : collect();
    // Empresa que o usuário está administrando (escolhida no menu lateral): tudo na tela é só dela
    $empresaAtiva = $contaAtiva ? app(\App\Support\Tenancy\EmitenteAtual::class)->emitente() : null;
    $empresasMenu = $emitentesAtivos->isNotEmpty() ? \App\Models\Emitente::where('ativo', true)->orderBy('razao_social')->get() : collect();
    $ambientes  = $empresaAtiva ? collect([$empresaAtiva->ambiente]) : collect();
    // Empresa ativa com a cobrança desligada (pelo master) → não mostra nada de créditos
    $semCreditos = app(\App\Support\Tenancy\EmitenteAtual::class)->semCobranca();
    $modoTeste  = $ambientes->isNotEmpty() && $ambientes->contains(2);
    $emProducao = $ambientes->isNotEmpty() && ! $modoTeste;

    // Área master (administrador da plataforma): menu próprio e atalho de ida e volta
    $ehMaster = (bool) ($usuario->admin_plataforma ?? false);
    $noMaster = request()->routeIs('master.*');

    // [rota, rótulo, permissão, padrão de rota ativa, ícone]
    $icones = [
        'inicio'   => 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
        'lotes'    => 'M12 16V4M7 9l5-5 5 5M5 20h14',
        'clientes' => 'M16 11a4 4 0 10-8 0 4 4 0 008 0zM4 21a8 8 0 0116 0',
        'creditos' => 'M3 7h18v12H3zM3 7l3-3h12l3 3M16 13h2',
        'empresas' => 'M4 21V5l8-2v18M12 9h8v12M7 9h2M7 13h2M7 17h2M15 13h2M15 17h2',
        'usuarios' => 'M17 20v-1a4 4 0 00-4-4H7a4 4 0 00-4 4v1M10 11a4 4 0 100-8 4 4 0 000 8zM21 20v-1a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8',
        'visao'    => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'nova'     => 'M12 5v14M5 12h14',
        'notas'    => 'M7 3h8l4 4v14H7zM15 3v4h4M10 12h6M10 16h6',
    ];

    if ($noMaster) {
        $grupos = ['Plataforma' => [
            ['master.visao', 'Visão geral', null, 'master.visao', 'visao'],
            ['master.clientes', 'Clientes', null, 'master.cliente*', 'clientes'],
        ]];
    } elseif ($contaAtiva) {
        $grupos = [
            'Notas' => [
                ['inicio', 'Início', 'consultar', 'inicio', 'inicio'],
                ['notas.manual', 'Emitir nota', 'emitir', 'notas.manual*', 'nova'],
                ['importacoes.index', 'Importações', 'consultar', 'importacoes.*', 'lotes'],
                ['notas.index', 'Notas', 'consultar', 'notas.index', 'notas'],
                ['tomadores.index', 'Tomadores (Clientes)', 'consultar', 'tomadores.*', 'clientes'],
                ['relatorios.index', 'Relatório do mês', 'consultar', 'relatorios.*', 'visao'],
            ],
            'Conta' => [
                ['creditos.index', 'Créditos', 'consultar', 'creditos.*', 'creditos'],
                ['emitentes.index', 'Emitentes', 'consultar', 'emitentes.*', 'empresas'],
                ['usuarios.index', 'Usuários', 'gerenciar-usuarios', 'usuarios.*', 'usuarios'],
                ['auditoria.index', 'Auditoria', 'gerenciar-conta', 'auditoria.*', 'notas'],
            ],
        ];
        if ($semCreditos) {
            $grupos['Conta'] = array_values(array_filter($grupos['Conta'], fn ($l) => $l[0] !== 'creditos.index'));
        }
    } else {
        $grupos = [];
    }
@endphp

{{-- Barra fina só no celular/tablet: abre o menu --}}
<div class="lg:hidden sticky top-0 z-40 h-14 px-4 flex items-center justify-between bg-casca text-white">
    <button type="button" @click="menu = true" class="w-11 h-11 -ms-2 inline-flex items-center justify-center rounded-lg hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" aria-label="Abrir menu" :aria-expanded="menu">
        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <a href="{{ route($noMaster ? 'master.visao' : 'dashboard') }}" class="font-semibold text-white no-underline">{{ config('saas.nome_produto', 'Emissor NFS-e') }}</a>
    @if ($contaAtiva && ! $noMaster && ! $semCreditos)
        <a href="{{ route('creditos.index') }}" class="text-sm text-white no-underline rounded-lg bg-white/10 px-3 py-1.5"><b class="font-mono">{{ number_format($saldo ?? 0, 0, ',', '.') }}</b> créd.</a>
    @else
        <span class="w-11"></span>
    @endif
</div>

<div x-show="menu" x-cloak @click="menu = false" class="lg:hidden fixed inset-0 z-40 bg-black/50" aria-hidden="true"></div>

<aside id="menu-lateral" aria-label="Principal"
       class="fixed inset-y-0 left-0 z-50 w-[272px] -translate-x-full transition-transform duration-200 lg:translate-x-0 lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:w-[256px] bg-casca text-casca-texto flex flex-col"
       :class="menu ? '!translate-x-0' : ''" @keydown.escape.window="menu = false">

    <div class="h-16 px-5 flex items-center justify-between shrink-0">
        <a href="{{ route($noMaster ? 'master.visao' : 'dashboard') }}" class="flex items-center gap-3 no-underline text-white">
            <span class="w-9 h-9 rounded-lg bg-brand-500 text-white flex items-center justify-center font-semibold" aria-hidden="true">N</span>
            <span class="font-semibold leading-tight">{{ config('saas.nome_produto', 'Emissor NFS-e') }}</span>
        </a>
        <button type="button" @click="menu = false" class="lg:hidden w-10 h-10 -me-2 inline-flex items-center justify-center rounded-lg hover:bg-white/10" aria-label="Fechar menu">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    @if ($noMaster)
        <div class="mx-4 mb-2 rounded-lg bg-white/10 px-3 py-2 text-[13px] text-white">Área master · somente leitura</div>
    @endif

    <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-5">
        @foreach ($grupos as $titulo => $links)
            @php $links = array_filter($links, fn ($l) => $l[2] === null || Gate::allows($l[2])); @endphp
            @if ($links)
                <div>
                    <div class="px-3 pb-1.5 text-[13px] text-casca-texto/70">{{ $titulo }}</div>
                    <ul class="space-y-0.5">
                        @foreach ($links as [$rota, $rotulo, , $ativo, $icone])
                            @php $on = request()->routeIs($ativo); @endphp
                            <li>
                                <a href="{{ route($rota) }}" @if ($on) aria-current="page" @endif
                                   @class(['flex items-center gap-3 min-h-[44px] px-3 rounded-lg text-[15px] font-medium no-underline transition-colors', 'bg-casca-600 text-white shadow-[inset_3px_0_0_#5FD1A0]' => $on, 'text-casca-texto hover:bg-white/10 hover:text-white' => ! $on])>
                                    <svg class="w-5 h-5 shrink-0 {{ $on ? 'text-[#5FD1A0]' : 'opacity-80' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icones[$icone] }}"/></svg>
                                    {{ $rotulo }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach

        @if ($ehMaster)
            <div>
                <div class="px-3 pb-1.5 text-[13px] text-casca-texto/70">Administração</div>
                <a href="{{ route($noMaster ? 'inicio' : 'master.visao') }}" class="flex items-center gap-3 min-h-[44px] px-3 rounded-lg text-[15px] font-medium no-underline text-casca-texto hover:bg-white/10 hover:text-white">
                    <svg class="w-5 h-5 shrink-0 opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $noMaster ? 'M15 18l-6-6 6-6' : $icones['visao'] }}"/></svg>
                    {{ $noMaster ? 'Voltar ao app' : 'Área master' }}
                </a>
            </div>
        @endif
    </nav>

    <div class="shrink-0 p-3 space-y-3 border-t border-white/10">
        @if ($contaAtiva && ! $noMaster && ! $semCreditos)
            <a href="{{ route('creditos.index') }}" class="flex items-center justify-between gap-3 rounded-xl bg-white/[.07] hover:bg-white/10 px-3.5 py-3 no-underline text-white" title="Créditos disponíveis">
                <span>
                    <span class="block text-[13px] text-casca-texto/80">Créditos</span>
                    <b class="font-mono text-2xl leading-none {{ ($saldo ?? 0) <= 0 ? 'text-amber-300' : '' }}">{{ number_format($saldo ?? 0, 0, ',', '.') }}</b>
                </span>
                <span class="text-sm font-semibold text-[#5FD1A0]">Recarregar</span>
            </a>
        @endif

        <div class="relative" x-data="{ aberto: false }" @click.outside="aberto = false" @keydown.escape="aberto = false">
            <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto" class="w-full flex items-center gap-3 rounded-xl px-2.5 py-2.5 text-left hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                <span class="w-9 h-9 shrink-0 rounded-full bg-casca-600 text-white flex items-center justify-center text-sm font-semibold" aria-hidden="true">{{ \Illuminate\Support\Str::of($usuario->name)->substr(0, 1)->upper() }}</span>
                <span class="min-w-0 flex-1 leading-tight">
                    <span class="block text-sm font-medium text-white truncate">{{ $contaAtiva?->nome ?? $usuario->name }}</span>
                    <span class="block text-xs text-casca-texto/75 truncate">{{ $usuario->name }}</span>
                </span>
                <svg class="w-4 h-4 shrink-0 opacity-70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M14.7 12.7a1 1 0 01-1.4 0L10 9.4l-3.3 3.3a1 1 0 01-1.4-1.4l4-4a1 1 0 011.4 0l4 4a1 1 0 010 1.4z" clip-rule="evenodd"/></svg>
            </button>
            <div x-show="aberto" x-cloak x-transition.opacity.duration.120ms class="absolute bottom-full left-0 right-0 mb-2 rounded-xl bg-white text-ink-900 shadow-xl border border-linha py-1.5 z-10">
                @foreach ($usuario->contas as $c)
                    @if (! $contaAtiva || $c->id !== $contaAtiva->id)
                        <form method="POST" action="{{ route('contas.trocar', $c->id) }}">@csrf
                            <button class="w-full text-left px-4 min-h-[40px] text-sm hover:bg-gray-50">Trocar para {{ $c->nome }}</button>
                        </form>
                    @endif
                @endforeach
                <a href="{{ route('contas.criar') }}" class="flex items-center px-4 min-h-[40px] text-sm no-underline text-ink-900 hover:bg-gray-50">Criar outra conta</a>
                <a href="{{ route('profile.edit') }}" class="flex items-center px-4 min-h-[40px] text-sm no-underline text-ink-900 hover:bg-gray-50">Meu perfil</a>
                <form method="POST" action="{{ route('logout') }}" class="border-t border-gray-100 mt-1 pt-1">@csrf
                    <button class="w-full text-left px-4 min-h-[40px] text-sm hover:bg-gray-50">Sair</button>
                </form>
            </div>
        </div>
    </div>
</aside>
