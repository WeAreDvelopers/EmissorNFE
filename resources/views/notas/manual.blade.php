<x-app-layout titulo="Emitir nota">
    @php
        $voltou = session()->hasOldInput();
        $primeiro = $emitentes->first();
        $emitenteId = (string) old('emitente_id', $primeiro?->id);
        $p = $padroes[$emitenteId] ?? ['codigo' => '', 'nbs' => '', 'descricao' => ''];
        $temPadrao = $p['codigo'] !== '' || $p['descricao'] !== '' || $p['nbs'] !== '';
        $usarPadrao = $voltou ? old('usar_padrao') === '1' : $temPadrao;
        $erro = fn ($campo) => $errors->first($campo);
        $avancado = $voltou && (bool) old('avancado');
        $fiscal = $p['fiscal'] ?? ['op' => 0];
        $adv = array_merge(\Illuminate\Support\Arr::only($fiscal, array_keys(array_diff_key($fiscal, ['fixos' => 1, 'op' => 1, 'deducao_ok' => 1]))), $avancado ? (array) old('ajustes', []) : []);
        if ($avancado) { $usarPadrao = false; }
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
                  avancado: @js($avancado), adv: @js($adv),
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
                            <label for="tomador_documento" class="campo-rotulo obrigatorio">CPF ou CNPJ do cliente</label>
                            <input id="tomador_documento" name="tomador_documento" x-model="doc" inputmode="numeric" autocomplete="off" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('tomador_documento')])>
                            @if ($erro('tomador_documento'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('tomador_documento') }}</p>@endif
                        </div>
                        <div>
                            <label for="tomador_nome" class="campo-rotulo obrigatorio">Nome ou razão social</label>
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

                <x-busca-servico nome="codigo_servico" rotulo="Código de serviço" obrigatorio :valor="old('codigo_servico', $usarPadrao ? $p['codigo'] : '')" somenteLeitura="usarPadrao" />
                @if ($erro('codigo_servico'))<p class="-mt-2 text-[13px] text-warn-800" role="alert">{{ $erro('codigo_servico') }}</p>@endif

                <div>
                    <label for="descricao" class="campo-rotulo obrigatorio">Descrição do serviço</label>
                    <textarea id="descricao" name="descricao" rows="3" x-model="descricao" :readonly="usarPadrao" :class="{ 'bg-gray-100 text-ink-700': usarPadrao }" @class(['campo-entrada', 'campo-invalido' => $erro('descricao')])></textarea>
                    @if ($erro('descricao'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('descricao') }}</p>@endif
                </div>

                <x-busca-servico nome="codigo_nbs" rotulo="NBS (opcional)" :valor="old('codigo_nbs', $usarPadrao ? $p['nbs'] : '')" somenteLeitura="usarPadrao"
                                 :url="route('nbs.buscar')" :tam="9" evento="definir-nbs" vazio="Selecione o item da NBS (código ou nome do serviço)" nenhum="Nenhum item da NBS encontrado. Tente outra palavra." />
                @if ($erro('codigo_nbs'))<p class="-mt-2 text-[13px] text-warn-800" role="alert">{{ $erro('codigo_nbs') }}</p>@endif
            </section>

            {{-- 4. Valor e data --}}
            <section class="card p-5 sm:p-6 space-y-4">
                <h2 class="text-lg font-semibold">4. Valor e data</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="valor" class="campo-rotulo obrigatorio">Valor (R$)</label>
                        <input id="valor" name="valor" value="{{ old('valor') }}" inputmode="decimal" placeholder="0,00" autocomplete="off" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('valor')]) autofocus>
                        @if ($erro('valor'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('valor') }}</p>@endif
                    </div>
                    <div>
                        <label for="data_competencia" class="campo-rotulo obrigatorio">Data de competência</label>
                        <input id="data_competencia" name="data_competencia" type="date" value="{{ old('data_competencia', now('America/Sao_Paulo')->toDateString()) }}" max="{{ now('America/Sao_Paulo')->toDateString() }}" @class(['campo-entrada', 'campo-invalido' => $erro('data_competencia')])>
                        @if ($erro('data_competencia'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('data_competencia') }}</p>@endif
                        <p class="campo-ajuda">Hoje, por padrão. Não pode ser data futura.</p>
                    </div>
                </div>
            </section>

            {{-- Nota completa: todos os campos enviados na emissão, preenchidos com a empresa e editáveis só para esta nota --}}
            <section class="space-y-4">
                <input type="hidden" name="avancado" value="1" :disabled="!avancado">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <button type="button" class="btn" @click="alternarTodosCampos()" :aria-expanded="avancado" aria-controls="nota-completa">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>
                        <span x-show="!avancado">Nota fiscal completa personalizada</span>
                        <span x-show="avancado" x-cloak>Voltar à nota simples</span>
                    </button>
                    <span class="text-[13px] text-ink-600" x-show="!avancado">A nota sai com os padrões da empresa. Abra para ver e alterar todos os campos.</span>
                </div>

                <div id="nota-completa" x-show="avancado" x-cloak class="card p-5 sm:p-6 space-y-7">
                    <p class="text-[13px] text-ink-700 leading-relaxed">Todos os campos enviados na emissão, já preenchidos com os dados da empresa. O que você alterar vale <b>só para esta nota</b>. Cliente, serviço, valor e data são os campos de cima, também liberados.</p>

                    {{-- Prestador e o que o portal bloqueia: só leitura --}}
                    <div class="space-y-3">
                        <h3 class="text-base font-semibold">Prestador</h3>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <template x-for="item in (p.fixos?.prestador || [])" :key="item[0]">
                                <div><label class="campo-rotulo" x-text="item[0]"></label><input class="campo-entrada bg-gray-100 text-ink-700" :value="item[1]" readonly tabindex="-1"></div>
                            </template>
                        </div>
                        <p class="campo-ajuda">Dados do cadastro da empresa: para mudar, edite a empresa.</p>
                    </div>

                    {{-- Local e serviço --}}
                    <div class="space-y-3">
                        <h3 class="text-base font-semibold">Local da prestação e serviço</h3>
                        <div class="grid gap-3 sm:grid-cols-6">
                            <div class="sm:col-span-4">
                                <label for="aj-mun" class="campo-rotulo">Município da prestação do serviço</label>
                                <input id="aj-mun" name="ajustes[mun_prestacao]" x-model="adv.mun_prestacao" :disabled="!avancado" autocomplete="off" @class(['campo-entrada', 'campo-invalido' => $erro('ajustes.mun_prestacao')])>
                                @if ($erro('ajustes.mun_prestacao'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.mun_prestacao') }}</p>@endif
                            </div>
                            <div class="sm:col-span-2">
                                <label for="aj-uf" class="campo-rotulo">UF</label>
                                <input id="aj-uf" name="ajustes[uf_prestacao]" x-model="adv.uf_prestacao" :disabled="!avancado" maxlength="2" autocomplete="off" @class(['campo-entrada font-mono uppercase', 'campo-invalido' => $erro('ajustes.uf_prestacao')])>
                                @if ($erro('ajustes.uf_prestacao'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.uf_prestacao') }}</p>@endif
                            </div>
                            <div class="sm:col-span-3" x-show="f.op != 2">
                                <label for="aj-ctm" class="campo-rotulo">Código complementar municipal</label>
                                <input id="aj-ctm" list="aj-ctm-lista" name="ajustes[cod_trib_mun]" x-model="adv.cod_trib_mun" :disabled="!avancado || f.op == 2" maxlength="3" inputmode="numeric" placeholder="001" autocomplete="off" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.cod_trib_mun')])>
                                @if ($erro('ajustes.cod_trib_mun'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.cod_trib_mun') }}</p>@endif
                                <datalist id="aj-ctm-lista"><template x-for="c in (f.codigos || [])" :key="c.codigo"><option :value="c.codigo" x-text="c.descricao"></option></template></datalist>
                                <p class="campo-ajuda" x-show="(f.codigos || []).find(c => c.codigo === adv.cod_trib_mun)?.descricao" x-text="(f.codigos || []).find(c => c.codigo === adv.cod_trib_mun)?.descricao"></p>
                            </div>
                        </div>
                    </div>

                    {{-- Tributação municipal --}}
                    <div class="space-y-3">
                        <h3 class="text-base font-semibold">Tributação municipal (ISSQN)</h3>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="aj-trib" class="campo-rotulo">Tributação do ISSQN sobre o serviço prestado</label>
                                <select id="aj-trib" name="ajustes[trib_issqn]" x-model="adv.trib_issqn" :disabled="!avancado" class="campo-entrada">
                                    @foreach (\App\Support\TabelasDps::TRIBUTACAO_ISSQN as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach
                                </select>
                                @if ($erro('ajustes.trib_issqn'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.trib_issqn') }}</p>@endif
                                <p class="campo-ajuda">Exportação de serviço exige tomador no exterior e ainda não está disponível.</p>
                            </div>
                            <div x-show="adv.trib_issqn == '2'" x-cloak>
                                <label for="aj-imu" class="campo-rotulo">Tipo de imunidade</label>
                                <select id="aj-imu" name="ajustes[tp_imunidade]" x-model="adv.tp_imunidade" :disabled="!avancado" @class(['campo-entrada', 'campo-invalido' => $erro('ajustes.tp_imunidade')])>
                                    <option value="">Escolha…</option>
                                    @foreach (\App\Support\TabelasDps::IMUNIDADES as $v => $r)<option value="{{ $v }}">{{ \Illuminate\Support\Str::limit($r, 70) }}</option>@endforeach
                                </select>
                                @if ($erro('ajustes.tp_imunidade'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.tp_imunidade') }}</p>@endif
                            </div>
                            <div x-show="adv.trib_issqn == '1'">
                                <label for="aj-ret" class="campo-rotulo">Há retenção do ISSQN pelo tomador?</label>
                                <select id="aj-ret" name="ajustes[retencao]" x-model="adv.retencao" :disabled="!avancado" class="campo-entrada">
                                    <option value="1">Não</option>
                                    <option value="2">Sim, o tomador retém o ISSQN</option>
                                </select>
                                @if ($erro('ajustes.retencao'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.retencao') }}</p>@endif
                                <p class="campo-ajuda">Com retenção, o cliente precisa ter o endereço completo no cadastro.</p>
                            </div>
                            <div x-show="adv.trib_issqn == '1' && f.deducao_ok" class="sm:col-span-2">
                                <label for="aj-ded" class="campo-rotulo">Dedução/redução da base de cálculo do ISSQN</label>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <select id="aj-ded" name="ajustes[deducao_tipo]" x-model="adv.deducao_tipo" :disabled="!avancado" @class(['campo-entrada', 'campo-invalido' => $erro('ajustes.deducao_tipo')])>
                                        <option value="none">Nenhuma</option>
                                        <option value="percentual">Por percentual (%)</option>
                                        <option value="valor">Por valor (R$)</option>
                                    </select>
                                    <input name="ajustes[deducao_valor]" x-model="adv.deducao_valor" x-show="adv.deducao_tipo != 'none'" :disabled="!avancado" inputmode="decimal" :placeholder="adv.deducao_tipo == 'percentual' ? 'Percentual, ex.: 10' : 'Valor, ex.: 50,00'" aria-label="Valor da dedução/redução" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.deducao_valor')])>
                                </div>
                                @if ($erro('ajustes.deducao_tipo'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.deducao_tipo') }}</p>@endif
                                @if ($erro('ajustes.deducao_valor'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.deducao_valor') }}</p>@endif
                                <p class="campo-ajuda">A prefeitura precisa permitir a dedução para este serviço; se não permitir, a nota é recusada.</p>
                            </div>
                            {{-- sem opção de dedução para esta empresa: o valor "nenhuma" segue junto --}}
                            <input type="hidden" name="ajustes[deducao_tipo]" value="none" :disabled="!avancado || (adv.trib_issqn == '1' && f.deducao_ok)">
                        </div>
                        <p class="campo-ajuda" x-show="adv.trib_issqn != '1'" x-cloak>Com imunidade ou não incidência não há regime especial, retenção nem dedução.</p>
                    </div>

                    {{-- Tributação federal (só não optante) --}}
                    <div class="space-y-3" x-show="f.op == 1">
                        <h3 class="text-base font-semibold">Tributação federal</h3>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="aj-cst" class="campo-rotulo">Situação tributária do PIS/COFINS</label>
                                <select id="aj-cst" name="ajustes[pis_cst]" x-model="adv.pis_cst" :disabled="!avancado || f.op != 1" class="campo-entrada">
                                    @foreach (\App\Support\TabelasDps::CST_PIS_COFINS as $v => $r)<option value="{{ $v }}">{{ $v }} - {{ $r }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="aj-pret" class="campo-rotulo">Tipo de retenção do PIS/COFINS/CSLL</label>
                                <select id="aj-pret" name="ajustes[pis_ret]" x-model="adv.pis_ret" :disabled="!avancado || f.op != 1" class="campo-entrada">
                                    @foreach (\App\Support\TabelasDps::RETENCAO_PIS_COFINS as $v => $r)<option value="{{ $v }}">{{ $v }} - {{ $r }}</option>@endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2 grid gap-3 sm:grid-cols-3" x-show="adv.pis_cst != '00'" x-cloak>
                                <div>
                                    <label for="aj-pbc" class="campo-rotulo">Base de cálculo do PIS/COFINS (R$)</label>
                                    <input id="aj-pbc" name="ajustes[pis_bc]" x-model="adv.pis_bc" :disabled="!avancado || f.op != 1" inputmode="decimal" placeholder="Em branco = valor do serviço" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.pis_bc')])>
                                    @if ($erro('ajustes.pis_bc'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.pis_bc') }}</p>@endif
                                </div>
                                <div>
                                    <label for="aj-ppis" class="campo-rotulo">Alíquota do PIS (%)</label>
                                    <input id="aj-ppis" name="ajustes[pis_aliq_pis]" x-model="adv.pis_aliq_pis" :disabled="!avancado || f.op != 1" inputmode="decimal" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.pis_aliq_pis')])>
                                    @if ($erro('ajustes.pis_aliq_pis'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.pis_aliq_pis') }}</p>@endif
                                </div>
                                <div>
                                    <label for="aj-pcof" class="campo-rotulo">Alíquota da COFINS (%)</label>
                                    <input id="aj-pcof" name="ajustes[pis_aliq_cofins]" x-model="adv.pis_aliq_cofins" :disabled="!avancado || f.op != 1" inputmode="decimal" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.pis_aliq_cofins')])>
                                    @if ($erro('ajustes.pis_aliq_cofins'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.pis_aliq_cofins') }}</p>@endif
                                </div>
                                <p class="sm:col-span-3 campo-ajuda">O valor do PIS e da COFINS é calculado (base × alíquota).</p>
                            </div>
                            <div>
                                <label for="aj-irrf" class="campo-rotulo">IRRF retido (R$)</label>
                                <input id="aj-irrf" name="ajustes[ret_irrf]" x-model="adv.ret_irrf" :disabled="!avancado || f.op != 1" inputmode="decimal" placeholder="Em branco = sem retenção" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.ret_irrf')])>
                                @if ($erro('ajustes.ret_irrf'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.ret_irrf') }}</p>@endif
                            </div>
                            <div>
                                <label for="aj-cp" class="campo-rotulo">Contribuição previdenciária retida (R$)</label>
                                <input id="aj-cp" name="ajustes[ret_cp]" x-model="adv.ret_cp" :disabled="!avancado || f.op != 1" inputmode="decimal" placeholder="Em branco = sem retenção" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.ret_cp')])>
                                @if ($erro('ajustes.ret_cp'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.ret_cp') }}</p>@endif
                            </div>
                            <div x-show="adv.pis_ret != '0'" x-cloak>
                                <label for="aj-csll" class="campo-rotulo">Contribuições sociais retidas - CSLL (R$)</label>
                                <input id="aj-csll" name="ajustes[ret_csll]" x-model="adv.ret_csll" :disabled="!avancado || f.op != 1" inputmode="decimal" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.ret_csll')])>
                                @if ($erro('ajustes.ret_csll'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.ret_csll') }}</p>@endif
                                <p class="campo-ajuda">Obrigatória nos tipos de retenção que incluem CSLL (exceto o 2).</p>
                            </div>
                        </div>
                    </div>
                    <p class="text-[13px] text-ink-600" x-show="f.op != 1">Tributação federal (PIS/COFINS, IRRF e contribuições): <span x-text="f.op == 2 ? 'não se aplica ao MEI.' : 'não é informada para empresa optante do Simples Nacional.'"></span></p>

                    {{-- Valor aproximado dos tributos: as formas que o Emissor Nacional oferece para o regime da empresa --}}
                    <div class="space-y-3">
                        <h3 class="text-base font-semibold">Valor aproximado dos tributos</h3>
                        <div class="space-y-2">
                            @foreach (\App\Models\Emitente::MODOS_TRIBUTOS_APROX as $valor => $rotulo)
                                <label class="flex items-start gap-2.5 text-sm cursor-pointer" x-show="(f.modos || []).includes('{{ $valor }}')">
                                    <input type="radio" name="ajustes[modo_tributos_aprox]" value="{{ $valor }}" x-model="adv.modo_tributos_aprox" :disabled="!avancado" class="mt-0.5 text-brand-600 focus:ring-brand-600">
                                    <span>{{ $rotulo }}</span>
                                </label>
                            @endforeach
                            @if ($erro('ajustes.modo_tributos_aprox'))<p class="text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.modo_tributos_aprox') }}</p>@endif
                        </div>

                        <div class="sm:max-w-xs" x-show="adv.modo_tributos_aprox == 'simples'" x-cloak>
                            <label for="aj-as" class="campo-rotulo">Alíquota do Simples Nacional (%)</label>
                            <input id="aj-as" name="ajustes[aliquota_simples]" x-model="adv.aliquota_simples" :disabled="!avancado || adv.modo_tributos_aprox != 'simples'" inputmode="decimal" autocomplete="off" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.aliquota_simples')])>
                            @if ($erro('ajustes.aliquota_simples'))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.aliquota_simples') }}</p>@endif
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3" x-show="adv.modo_tributos_aprox == 'percentual'" x-cloak>
                            @foreach (['federal' => 'Federal', 'estadual' => 'Estadual', 'municipal' => 'Municipal'] as $k => $nome)
                                <div>
                                    <label for="aj-t-{{ $k }}" class="campo-rotulo">{{ $nome }} (%)</label>
                                    <input id="aj-t-{{ $k }}" name="ajustes[trib_{{ $k }}]" x-model="adv.trib_{{ $k }}" :disabled="!avancado || adv.modo_tributos_aprox != 'percentual'" inputmode="decimal" autocomplete="off" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.trib_'.$k)])>
                                    @if ($erro('ajustes.trib_'.$k))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.trib_'.$k) }}</p>@endif
                                </div>
                            @endforeach
                        </div>

                        <div x-show="adv.modo_tributos_aprox == 'monetario'" x-cloak class="space-y-2">
                            <div class="grid gap-3 sm:grid-cols-3">
                                @foreach (['federal' => 'Federal', 'estadual' => 'Estadual', 'municipal' => 'Municipal'] as $k => $nome)
                                    <div>
                                        <label for="aj-v-{{ $k }}" class="campo-rotulo">{{ $nome }} (R$)</label>
                                        <input id="aj-v-{{ $k }}" name="ajustes[val_{{ $k }}]" x-model="adv.val_{{ $k }}" :disabled="!avancado || adv.modo_tributos_aprox != 'monetario'" inputmode="decimal" placeholder="Calculado" autocomplete="off" @class(['campo-entrada font-mono', 'campo-invalido' => $erro('ajustes.val_'.$k)])>
                                        @if ($erro('ajustes.val_'.$k))<p class="mt-1 text-[13px] text-warn-800" role="alert">{{ $erro('ajustes.val_'.$k) }}</p>@endif
                                    </div>
                                @endforeach
                            </div>
                            <p class="campo-ajuda">Em branco, o valor é calculado com os percentuais da empresa sobre o valor do serviço.</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <h3 class="text-base font-semibold">Bloqueado no Emissor Nacional</h3>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <template x-for="item in (p.fixos?.bloqueados || [])" :key="item[0]">
                                <div><label class="campo-rotulo" x-text="item[0]"></label><input class="campo-entrada bg-gray-100 text-ink-700" :value="item[1]" readonly tabindex="-1"></div>
                            </template>
                        </div>
                        <p class="campo-ajuda">O próprio portal não deixa preencher estes campos, por isso não são enviados.</p>
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
