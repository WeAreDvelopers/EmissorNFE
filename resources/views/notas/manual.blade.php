<x-app-layout titulo="Emitir nota">
    @php
        $voltou = session()->hasOldInput();
        $primeiro = $emitentes->first();
        $emitenteId = (string) old('emitente_id', $primeiro?->id);
        $p = $padroes[$emitenteId] ?? ['codigo' => '', 'nbs' => '', 'descricao' => ''];
        $temPadrao = $p['codigo'] !== '' || $p['descricao'] !== '' || $p['nbs'] !== '';
        $usarPadrao = $voltou ? old('usar_padrao') === '1' : $temPadrao;
        $erro = fn ($campo) => $errors->first($campo);
    @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-1">
            <h1 class="text-3xl font-semibold">Emitir nota</h1>
            <p class="text-[15px] text-ink-700">Uma nota por vez. O serviço já vem com os padrões da empresa: informe cliente e valor. Antes de emitir, você confere o resumo.</p>
        </div>

        @if ($emitentes->isEmpty())
            <div class="card p-6">
                <p class="text-ink-700">Cadastre a empresa que vai emitir as notas para poder emitir.</p>
                @can('gerenciar-emitentes')<a href="{{ route('emitentes.criar') }}" class="btn btn-primary mt-4">Cadastrar empresa</a>@endcan
            </div>
        @else
        <form method="POST" action="{{ route('notas.manual.conferir') }}" class="max-w-3xl space-y-5"
              x-data="notaManual({
                  padroes: @js($padroes), emitente: @js($emitenteId), usarPadrao: @js($usarPadrao),
                  nbs: @js((string) old('codigo_nbs', $usarPadrao ? $p['nbs'] : '')),
                  descricao: @js((string) old('descricao', $usarPadrao ? $p['descricao'] : '')),
                  semTomador: @js((bool) old('sem_tomador', false)),
                  doc: @js((string) old('tomador_documento', '')), nome: @js((string) old('tomador_nome', '')), email: @js((string) old('tomador_email', '')),
                  urlClientes: @js(route('tomadores.buscar')),
              })">
            @csrf

            @if (session('duplicidade'))
                <div role="alert" class="rounded-xl bg-amber-50 border border-amber-300 px-4 py-3 text-sm text-amber-900 space-y-2">
                    <div><b>Atenção:</b> {{ session('duplicidade') }}</div>
                </div>
            @endif

            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900">
                    <b>Não deu para emitir ainda.</b> Corrija o que está marcado abaixo:
                    <ul class="list-disc ms-5 mt-1">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
                </div>
            @endif

            <div x-show="padroes[emitente]?.teste" x-cloak class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
                <b>Modo teste.</b> A nota emitida aqui não tem valor fiscal.
            </div>

            {{-- 1. Empresa --}}
            <section class="card p-5 sm:p-6 space-y-4">
                <h2 class="text-lg font-semibold">1. Quem emite</h2>
                @if ($emitentes->count() > 1)
                    <x-campo nome="emitente_id" tipo="select" rotulo="Empresa que emite" x-model="emitente">
                        @foreach ($emitentes as $e)
                            <option value="{{ $e->id }}" @selected($emitenteId == $e->id)>{{ $e->razao_social }} · {{ \App\Support\Documento::formatar($e->cnpj) }}</option>
                        @endforeach
                    </x-campo>
                @else
                    <input type="hidden" name="emitente_id" value="{{ $primeiro->id }}">
                    <p class="text-[15px]"><b>{{ $primeiro->razao_social }}</b> <span class="font-mono text-ink-600">· {{ \App\Support\Documento::formatar($primeiro->cnpj) }}</span></p>
                @endif
            </section>

            {{-- 2. Cliente --}}
            <section class="card p-5 sm:p-6 space-y-4">
                <h2 class="text-lg font-semibold">2. Para quem</h2>

                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="sem_tomador" value="1" x-model="semTomador" class="rounded text-brand-600 focus:ring-brand-600"> Sem destinatário informado (nota sem dados do cliente)</label>

                <div x-show="!semTomador" class="space-y-4">
                    <div class="relative" @click.outside="abertoCli = false">
                        <label for="busca-cliente" class="campo-rotulo">Buscar cliente já cadastrado</label>
                        <input id="busca-cliente" x-model="q" @input.debounce.250ms="buscarCliente()" @keydown.enter.prevent="clientes.length && escolherCliente(clientes[0])" @keydown.escape="abertoCli = false"
                               autocomplete="off" placeholder="Nome, CPF ou CNPJ" class="campo-entrada" role="combobox" :aria-expanded="abertoCli">
                        <ul x-show="abertoCli" x-cloak class="absolute z-30 mt-1 w-full max-h-72 overflow-auto rounded-lg border border-gray-300 bg-white shadow-lg" role="listbox">
                            <template x-for="c in clientes" :key="c.documento">
                                <li><button type="button" @click="escolherCliente(c)" class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 border-b border-gray-100 last:border-0">
                                    <span class="font-medium" x-text="c.nome"></span>
                                    <span class="block text-xs text-ink-600"><span class="font-mono" x-text="c.documento"></span><span x-show="c.cidade" x-text="' · ' + c.cidade"></span></span>
                                </button></li>
                            </template>
                            <li x-show="!clientes.length" class="px-3 py-2 text-sm text-ink-600">Nenhum cliente encontrado. Preencha os dados abaixo para cadastrar um novo.</li>
                        </ul>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="tomador_documento" class="campo-rotulo">CPF ou CNPJ do cliente</label>
                            <input id="tomador_documento" name="tomador_documento" x-model="doc" inputmode="numeric" autocomplete="off" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('tomador_documento')])>
                            @if ($erro('tomador_documento'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('tomador_documento') }}</p>@endif
                        </div>
                        <div>
                            <label for="tomador_nome" class="campo-rotulo">Nome ou razão social</label>
                            <input id="tomador_nome" name="tomador_nome" x-model="nome" autocomplete="off" @class(['campo-entrada', 'campo-invalido' => $erro('tomador_nome')])>
                            @if ($erro('tomador_nome'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('tomador_nome') }}</p>@endif
                        </div>
                    </div>
                    <div>
                        <label for="tomador_email" class="campo-rotulo">E-mail do cliente (opcional)</label>
                        <input id="tomador_email" name="tomador_email" type="email" x-model="email" autocomplete="off" @class(['campo-entrada', 'campo-invalido' => $erro('tomador_email')])>
                        @if ($erro('tomador_email'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('tomador_email') }}</p>@endif
                        <p class="campo-ajuda">Cliente novo é cadastrado com estes dados. O endereço pode ser completado depois em Clientes.</p>
                    </div>
                </div>
            </section>

            {{-- 3. Serviço: já vem com os padrões da empresa --}}
            <section class="card p-5 sm:p-6 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">3. O serviço</h2>
                    <label class="flex items-center gap-2 text-sm" x-show="temPadrao" x-cloak>
                        <input type="checkbox" name="usar_padrao" value="1" x-model="usarPadrao" class="rounded text-brand-600 focus:ring-brand-600">
                        <span>Usar os padrões da empresa</span>
                    </label>
                </div>
                <p class="text-[13px] text-ink-600" x-show="usarPadrao" x-cloak>Preenchido com os padrões cadastrados na empresa. Desmarque a opção acima para alterar só nesta nota.</p>
                <p class="text-[13px] text-ink-600" x-show="!temPadrao" x-cloak>Esta empresa ainda não tem padrões de serviço. Preencha abaixo ou cadastre os padrões em Empresas que emitem.</p>

                <x-busca-servico nome="codigo_servico" rotulo="Código de serviço" :valor="old('codigo_servico', $usarPadrao ? $p['codigo'] : '')" somenteLeitura="usarPadrao" />
                @if ($erro('codigo_servico'))<p class="-mt-2 text-[13px] text-warn-800" role="alert">{{ $erro('codigo_servico') }}</p>@endif

                <div>
                    <label for="descricao" class="campo-rotulo">Descrição do serviço</label>
                    <textarea id="descricao" name="descricao" rows="3" x-model="descricao" :readonly="usarPadrao" :class="{ 'bg-gray-100 text-ink-700': usarPadrao }" @class(['campo-entrada', 'campo-invalido' => $erro('descricao')])></textarea>
                    @if ($erro('descricao'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('descricao') }}</p>@endif
                </div>

                <div class="sm:max-w-xs">
                    <label for="codigo_nbs" class="campo-rotulo">NBS (opcional)</label>
                    <input id="codigo_nbs" name="codigo_nbs" x-model="nbs" inputmode="numeric" maxlength="9" :readonly="usarPadrao" :class="{ 'bg-gray-100 text-ink-700': usarPadrao }" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('codigo_nbs')])>
                    @if ($erro('codigo_nbs'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('codigo_nbs') }}</p>@endif
                </div>
            </section>

            {{-- 4. Valor e data --}}
            <section class="card p-5 sm:p-6 space-y-4">
                <h2 class="text-lg font-semibold">4. Valor e data</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="valor" class="campo-rotulo">Valor (R$)</label>
                        <input id="valor" name="valor" value="{{ old('valor') }}" inputmode="decimal" placeholder="0,00" autocomplete="off" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('valor')]) autofocus>
                        @if ($erro('valor'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('valor') }}</p>@endif
                    </div>
                    <div>
                        <label for="data_competencia" class="campo-rotulo">Data de competência</label>
                        <input id="data_competencia" name="data_competencia" type="date" value="{{ old('data_competencia', now('America/Sao_Paulo')->toDateString()) }}" max="{{ now('America/Sao_Paulo')->toDateString() }}" @class(['campo-entrada', 'campo-invalido' => $erro('data_competencia')])>
                        @if ($erro('data_competencia'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('data_competencia') }}</p>@endif
                        <p class="campo-ajuda">Hoje, por padrão. Não pode ser data futura.</p>
                    </div>
                </div>
            </section>

            <div class="rounded-lg bg-chao px-3.5 py-3 text-[13px] leading-relaxed text-ink-700">
                <span x-show="padroes[emitente]?.sem_custo" x-cloak>{{ 'Esta empresa não gasta crédito para emitir (modo teste ou sem cobrança).' }}</span>
                <span x-show="!padroes[emitente]?.sem_custo" x-cloak>Ao emitir (no próximo passo), 1 crédito é reservado e só é cobrado se a prefeitura autorizar.</span>
            </div>

            <div class="flex items-center gap-4">
                <button class="btn btn-primary btn-lg">Conferir dados</button>
                <a href="{{ route('notas.index') }}" class="link text-sm">Cancelar</a>
            </div>
        </form>
        @endif
    </div>
</x-app-layout>
