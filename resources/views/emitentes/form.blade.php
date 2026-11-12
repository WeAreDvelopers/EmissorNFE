<x-app-layout :titulo="$emitente->exists ? 'Editar emitente' : 'Novo emitente'">
    @php
        $op = old('op_simples_nacional', $emitente->op_simples_nacional ?? 3);
        $trib = $emitente->tributos_aprox ?? [];
        $novo = ! $emitente->exists;
        $semEmpresaAinda = $novo && \App\Models\Emitente::doesntExist();
    @endphp

    <div class="max-w-[1120px] mx-auto px-4 sm:px-8 py-8 space-y-6"
         x-data="{
            historico: false,
            op: '{{ $op }}',
            modo: @js(old('modo_tributos_aprox', $emitente->exists ? $emitente->modoTributosAprox() : '')),
            cst: @js(old('pis_cofins_cst', $emitente->pis_cofins_cst ?: '00')),
            trib: @js((string) old('trib_issqn', $emitente->trib_issqn ?: 1)),
            codigos: @js(old('codigos', $emitente->listaCodigosMunicipais())),
            addCodigo() { this.codigos.push({ codigo: '', descricao: '', servico: '' }); },
            removerCodigo(i) { this.codigos.splice(i, 1); },
            ded: @js(old('deducao_tipo', $emitente->deducao_tipo ?: 'none')),
            get modos() { return ({ 1: ['percentual', 'monetario'], 3: ['simples', 'percentual', 'monetario'], 2: ['nenhum', 'percentual', 'monetario'] })[this.op] || []; },
            get modoAtual() { return this.modos.includes(this.modo) ? this.modo : 'percentual'; },
            cnpj: @js(old('cnpj', \App\Support\Documento::formatar($emitente->cnpj))),
            razao: @js(old('razao_social', $emitente->razao_social)),
            lendo: false, lido: false, erro: null, validade: null, vencido: false,
            municipio: @js(old('codigo_municipio', $emitente->codigo_municipio)), cidade: null, cidadeNome: '', uf: '', cidades: [],
            ufPrest: @js(old('uf_prestacao', $emitente->codigo_municipio_prestacao ? substr((string) strrchr((string) \App\Support\MunicipiosIbge::rotulo($emitente->codigo_municipio_prestacao), '/'), 1) : '')),
            munPrest: @js(old('municipio_prestacao', $emitente->codigo_municipio_prestacao ? strtok((string) \App\Support\MunicipiosIbge::rotulo($emitente->codigo_municipio_prestacao), '/') : '')),
            cidadesPrest: [],
            localPrest: @js(old('local_prestacao', $emitente->local_prestacao ?: 'emitente')),
            informarSerie: @js((bool) old('informar_serie_dps', $emitente->exists ? $emitente->informar_serie_dps : false)),
            async carregarCidadesPrest() {
                if (!this.ufPrest) { this.cidadesPrest = []; return; }
                try { const r = await fetch('https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + this.ufPrest + '/municipios?orderBy=nome'); this.cidadesPrest = (await r.json()).map(c => c.nome); } catch (e) { this.cidadesPrest = []; }
            },
            logoNova: null, removerLogo: false,
            escolherLogo(f) { this.logoNova = f ? URL.createObjectURL(f) : null; if (f) this.removerLogo = false; },
            async ler() {
                const f = this.$refs.certificado.files[0];
                if (!f || !this.$refs.senha.value) { this.erro = 'Escolha o arquivo e digite a senha do certificado.'; return; }
                this.lendo = true; this.erro = null; this.lido = false;
                const dados = new FormData(); dados.append('certificado', f); dados.append('certificado_senha', this.$refs.senha.value);
                try {
                    const r = await fetch('{{ route('emitentes.certificado.ler') }}', { method: 'POST', body: dados, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                    const j = await r.json();
                    if (!r.ok) { this.erro = j.message || 'Não foi possível ler o certificado.'; }
                    else {
                        const soDigitos = v => String(v || '').replace(/\D/g, '');
                        if (soDigitos(this.cnpj) && j.cnpj && soDigitos(this.cnpj) !== soDigitos(j.cnpj)) {
                            this.erro = 'Este certificado é do CNPJ ' + j.cnpj + (j.razao_social ? ' (' + j.razao_social + ')' : '') + ', diferente do CNPJ da empresa (' + this.cnpj + '). Envie o certificado da própria empresa. Os dados da empresa não foram alterados.';
                        } else {
                            this.lido = true; if (!soDigitos(this.cnpj) && j.cnpj) this.cnpj = j.cnpj; if (!this.razao && j.razao_social) this.razao = j.razao_social; this.validade = j.valido_ate; this.vencido = j.vencido;
                        }
                    }
                } catch (e) { this.erro = 'Não foi possível ler o certificado agora. Tente de novo.'; }
                this.lendo = false;
            },
            async carregarCidades() {
                if (!this.uf) { this.cidades = []; return; }
                try { const r = await fetch('https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + this.uf + '/municipios?orderBy=nome'); this.cidades = (await r.json()).map(c => ({ id: String(c.id), nome: c.nome })); } catch (e) { this.cidades = []; }
            },
            escolherCidade(nome) { const c = this.cidades.find(x => x.nome.toLowerCase() === nome.toLowerCase()); if (c) { this.municipio = c.id; this.cidade = c.nome + ' · ' + this.uf; this.cidadeNome = c.nome; } },
            async init() {
                this.carregarCidadesPrest();
                if (this.municipio) { try { const r = await fetch('https://servicodados.ibge.gov.br/api/v1/localidades/municipios/' + this.municipio); const j = await r.json(); if (j && j.nome) { const sigla = j.microrregiao.mesorregiao.UF.sigla; this.cidade = j.nome + ' · ' + sigla; this.uf = sigla; this.cidadeNome = j.nome; await this.carregarCidades(); } } catch (e) {} }
            }
         }">

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-2">
                <a href="{{ route('emitentes.index') }}" class="link text-sm">← Emitentes</a>
                <h1 class="text-3xl font-semibold">{{ $semEmpresaAinda ? 'Vamos cadastrar a sua empresa' : ($novo ? 'Novo emitente' : 'Editar emitente') }}</h1>
                <p class="text-[15px] text-ink-700">O certificado digital A1 assina as suas notas. A gente lê os dados dele para você não digitar nada.</p>
            </div>
            @if ($emitente->exists)
                <button type="button" class="btn" @click="historico = ! historico" :aria-expanded="historico" aria-controls="historico-alteracoes" title="Ver o cadastro inicial e as alterações desta empresa">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>
                    <span x-text="historico ? 'Ocultar histórico' : 'Histórico de alterações'">Histórico de alterações</span>
                    @if (($alteracoes ?? collect())->count())<span class="text-xs rounded-full bg-gray-100 px-2 py-0.5">{{ $alteracoes->count() }}</span>@endif
                </button>
            @endif
        </div>

        @if ($emitente->exists)
            @php
                $rotulosHist = \App\Models\EmitenteAlteracao::CAMPOS;
                $fmtHist = fn ($v) => is_array($v) ? collect($v)->map(fn ($x, $k) => (is_string($k) ? $k.': ' : '').$x)->implode(' · ') : ($v === null || $v === '' ? '—' : (is_bool($v) ? ($v ? 'Sim' : 'Não') : $v));
            @endphp
            <section id="historico-alteracoes" x-show="historico" x-cloak class="card p-5 sm:p-6">
                <h2 class="text-base font-semibold">Histórico de alterações</h2>
                <p class="text-[13px] text-ink-600 mt-0.5">O primeiro cadastro e cada alteração, com data, usuário, o que havia antes e o que passou a valer. Senhas e o arquivo do certificado nunca são guardados.</p>
                @forelse (($alteracoes ?? collect()) as $a)
                    <div class="tabela-linha py-3 text-sm">
                        <div class="text-xs text-ink-600"><b>{{ $a->origem === 'cadastro' ? 'Primeiro cadastro' : 'Alteração' }}</b> · {{ $a->created_at->format('d/m/Y H:i') }}@if ($a->user) · {{ $a->user->name }}@endif</div>
                        @foreach ($a->campos as $campo => $mud)
                            @if (isset($rotulosHist[$campo]))
                                <div class="leading-snug"><b>{{ $rotulosHist[$campo] }}:</b> <span class="text-ink-600">{{ $fmtHist($mud['de'] ?? null) }}</span> → {{ $fmtHist($mud['para'] ?? null) }}</div>
                            @endif
                        @endforeach
                    </div>
                @empty
                    <p class="text-sm text-ink-600 mt-3">Sem alterações registradas ainda. A partir de agora, cada alteração desta empresa fica guardada aqui.</p>
                @endforelse
            </section>
        @endif

        <div class="flex flex-wrap gap-6 items-start">
            <form method="POST" enctype="multipart/form-data"
                  action="{{ $emitente->exists ? route('emitentes.update', $emitente) : route('emitentes.store') }}"
                  class="flex-[999_1_620px] min-w-0 space-y-5">
                @csrf
                @if ($emitente->exists) @method('PUT') @endif

                <section class="card p-5 sm:p-6 space-y-4">
                    <h2 class="text-lg font-semibold">1. Certificado digital A1 (.pfx ou .p12)</h2>
                    @if ($emitente->certificado_arquivo)
                        @php
                            $dias = $emitente->certificadoVenceEmDias();
                            $vencido = $dias !== null && $dias < 0;
                            $vencendo = ! $vencido && $dias !== null && $dias <= 30;
                        @endphp
                        <div @class(['flex items-center gap-4 rounded-xl border-2 px-4 py-3.5', 'border-brand-600 bg-ok-50' => ! $vencido && ! $vencendo, 'border-amber-400 bg-amber-50' => $vencendo, 'border-warn-600 bg-warn-50' => $vencido]) role="status">
                            <span @class(['w-11 h-11 shrink-0 rounded-full flex items-center justify-center text-white', 'bg-brand-600' => ! $vencido && ! $vencendo, 'bg-amber-500' => $vencendo, 'bg-warn-600' => $vencido]) aria-hidden="true">
                                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">@if ($vencido)<path d="M12 8v5M12 17h.01"/>@else<path d="M5 13l4 4L19 7"/>@endif</svg>
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold text-[15px] text-ink-900">{{ $vencido ? 'Certificado vencido' : 'Certificado já enviado' }}</div>
                                <div class="text-sm text-ink-700">
                                    @if ($emitente->certificado_validade)Válido até <b>{{ $emitente->certificado_validade->format('d/m/Y') }}</b>@if ($dias !== null && ! $vencido) · faltam {{ $dias }} {{ $dias === 1 ? 'dia' : 'dias' }}@endif @endif
                                    · guardado com criptografia
                                </div>
                                <div class="text-[13px] text-ink-600 mt-0.5">Não precisa enviar de novo. Escolha outro arquivo abaixo só se quiser substituir.</div>
                            </div>
                        </div>
                    @endif
                    @unless ($emitente->certificado_arquivo)
                        <div class="rounded-lg bg-amber-50 border border-amber-200 px-3.5 py-3 text-sm text-amber-900" role="note">Sem certificado, você já pode salvar os dados da empresa. Só não é possível emitir notas nem ativar produção até enviar o certificado.</div>
                    @endunless
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="certificado" class="campo-rotulo">Arquivo do certificado</label>
                            <input id="certificado" name="certificado" type="file" accept=".pfx,.p12" x-ref="certificado" @change="lido = false; erro = null" class="campo-entrada py-2">
                        </div>
                        <div>
                            <label for="certificado_senha" class="campo-rotulo">Senha do certificado</label>
                            <input id="certificado_senha" name="certificado_senha" type="password" autocomplete="off" x-ref="senha" @change="lido = false; erro = null" class="campo-entrada">
                            <p class="campo-ajuda">Fica guardada criptografada. Nunca aparece em tela nem em registro.</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" class="btn btn-sm" @click="ler()" :disabled="lendo"><span x-show="!lendo">Ler certificado e preencher</span><span x-show="lendo" x-cloak>Lendo…</span></button>
                    </div>
                    <div x-show="erro" x-cloak class="rounded-lg bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900" role="alert" x-text="erro"></div>
                    <div x-show="lido" x-cloak class="rounded-lg bg-ok-50 border border-brand-200 px-4 py-3.5 text-sm text-ok-800 space-y-1" role="status">
                        <div class="font-semibold">Certificado lido. Confira os dados abaixo.</div>
                        <div>Válido até <b x-text="validade"></b> <span x-show="vencido" class="text-warn-800 font-semibold">(VENCIDO: peça um novo ao seu contador)</span><span x-show="!vencido">· avisamos 30 dias antes de vencer.</span></div>
                    </div>
                </section>

                <section class="card p-5 sm:p-6 space-y-4">
                    <h2 class="text-lg font-semibold">2. Dados da empresa</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-campo nome="cnpj" obrigatorio rotulo="CNPJ" mono x-model="cnpj" ajuda="Vem do certificado quando possível." required />
                        <x-campo nome="razao_social" rotulo="Razão social" x-model="razao" required />
                        <x-campo nome="inscricao_municipal" rotulo="Inscrição municipal (se tiver)" :valor="$emitente->inscricao_municipal" placeholder="Opcional" />

                        <div class="sm:col-span-2">
                            <span class="campo-rotulo">Logo da empresa (opcional)</span>
                            <div class="mt-1 flex flex-wrap items-center gap-4">
                                <span class="w-16 h-16 shrink-0 rounded-xl border border-gray-300 bg-white flex items-center justify-center overflow-hidden">
                                    <img x-show="logoNova" x-cloak :src="logoNova" alt="Prévia da logo" class="max-w-full max-h-full object-contain">
                                    @if ($emitente->logo_url)<img x-show="!logoNova && !removerLogo" src="{{ $emitente->logo_url }}" alt="Logo atual" class="max-w-full max-h-full object-contain">@endif
                                    <span x-show="!logoNova && ({{ $emitente->logo_url ? 'removerLogo' : 'true' }})" class="text-xs text-ink-600 text-center leading-tight">sem logo</span>
                                </span>
                                <div class="flex-1 min-w-[220px] space-y-1.5">
                                    <input id="logo" name="logo" type="file" accept=".png,.jpg,.jpeg,.webp" @change="escolherLogo($event.target.files[0])" class="campo-entrada py-2 !mt-0">
                                    @if ($emitente->logo_url)
                                        <label class="flex items-center gap-2 text-[13px] text-ink-700"><input type="checkbox" name="remover_logo" value="1" x-model="removerLogo" class="rounded text-brand-600 focus:ring-brand-600"> Remover a logo atual</label>
                                    @endif
                                    <p class="campo-ajuda !mt-0">PNG, JPG ou WebP, até 2 MB. Aparece na hora de importar as notas.</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <span class="campo-rotulo">Cidade</span>
                            <div class="mt-1 grid grid-cols-[84px_minmax(0,1fr)] gap-2">
                                <select x-model="uf" @change="carregarCidades()" class="campo-entrada !mt-0" aria-label="Estado">
                                    <option value="">UF</option>
                                    @foreach (['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $sigla)<option>{{ $sigla }}</option>@endforeach
                                </select>
                                <input list="lista-cidades" x-model="cidadeNome" placeholder="Digite o nome da cidade" @change="escolherCidade($event.target.value)" class="campo-entrada !mt-0" :disabled="!cidades.length" aria-label="Cidade">
                                <datalist id="lista-cidades"><template x-for="c in cidades" :key="c.id"><option :value="c.nome"></option></template></datalist>
                            </div>
                            <p class="campo-ajuda"><span x-show="cidade" x-text="cidade"></span><span x-show="!cidade">Escolha o estado e depois a cidade.</span> · código IBGE <b class="font-mono" x-text="municipio || '—'"></b></p>
                            <input type="hidden" name="codigo_municipio" :value="municipio">
                        </div>
                    </div>
                </section>

                <section class="card p-5 sm:p-6 space-y-4">
                    <h2 class="text-lg font-semibold">3. Como a empresa é tributada</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-campo nome="op_simples_nacional" obrigatorio tipo="select" rotulo="Situação no Simples Nacional" x-model="op" ajuda="Não sabe? Pergunte ao seu contador ou veja no cartão CNPJ.">
                            <option value="1">Não optante</option>
                            <option value="2">MEI</option>
                            <option value="3">ME/EPP optante</option>
                        </x-campo>
                        <x-campo nome="regime_especial" obrigatorio tipo="select" rotulo="Regime especial de tributação">
                            @foreach ([0 => 'Nenhum', 1 => 'Ato cooperado', 2 => 'Estimativa', 3 => 'Sociedade de profissionais', 4 => 'Cooperativa', 5 => 'MEI', 6 => 'ME/EPP', 9 => 'Outros'] as $v => $r)
                                <option value="{{ $v }}" @selected(old('regime_especial', $emitente->regime_especial ?? 0) == $v)>{{ $r }}</option>
                            @endforeach
                        </x-campo>
                    </div>

                    <div x-show="op == 3" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-campo nome="regime_apuracao_sn" obrigatorio tipo="select" rotulo="Regime de Apuração dos Tributos no Simples Nacional" ajuda="Atenção: a segunda opção é só para empresas que ultrapassaram o sublimite de receita do Simples (R$ 3,6 milhões por ano) e passaram a recolher o ISS fora do DAS.">
                            @foreach ([1 => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional', 2 => 'Regime de apuração dos tributos federais pelo Simples Nacional e o ISSQN pela NFS-e conforme respectiva legislação municipal do tributo', 3 => 'Regime de apuração dos tributos federais e municipal pela NFS-e conforme respectivas legislações federal e municipal de cada tributo'] as $v => $r)
                                <option value="{{ $v }}" @selected(old('regime_apuracao_sn', $emitente->regime_apuracao_sn ?? 1) == $v)>{{ $r }}</option>
                            @endforeach
                        </x-campo>
                    </div>

                    {{-- Tributação municipal (ISSQN) padrão: o que o Emissor Nacional pergunta na tela Tributação Municipal --}}
                    <fieldset class="space-y-3 pt-2">
                        <legend class="text-sm font-semibold">Tributação municipal (ISSQN)</legend>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-campo nome="trib_issqn" obrigatorio tipo="select" rotulo="O serviço é caso de imunidade, exportação ou não incidência do ISSQN?" x-model="trib" ajuda="Quase sempre é operação tributável. Exportação de serviço ainda não está disponível (exige tomador no exterior).">
                                @foreach (\App\Support\TabelasDps::TRIBUTACAO_ISSQN as $v => $r)
                                    <option value="{{ $v }}" @selected((string) old('trib_issqn', $emitente->trib_issqn ?: 1) === (string) $v)>{{ $v === 1 ? 'Não: operação tributável' : 'Sim: '.mb_strtolower($r) }}</option>
                                @endforeach
                            </x-campo>
                            <div x-show="trib == '2'" x-cloak>
                                <x-campo nome="tp_imunidade" obrigatorio tipo="select" rotulo="Tipo de imunidade">
                                    <option value="">Escolha…</option>
                                    @foreach (\App\Support\TabelasDps::IMUNIDADES as $v => $r)
                                        <option value="{{ $v }}" @selected((string) old('tp_imunidade', $emitente->tp_imunidade) === (string) $v)>{{ \Illuminate\Support\Str::limit($r, 80) }}</option>
                                    @endforeach
                                </x-campo>
                            </div>
                            <div x-show="trib == '1'">
                                <x-campo nome="retencao_issqn" obrigatorio tipo="select" rotulo="Há retenção do ISSQN pelo tomador?" ajuda="Com retenção, o cliente precisa ter o endereço completo no cadastro. Também dá para decidir em cada nota.">
                                    <option value="1" @selected((int) old('retencao_issqn', $emitente->retencao_issqn ?: 1) === 1)>Não</option>
                                    <option value="2" @selected((int) old('retencao_issqn', $emitente->retencao_issqn ?: 1) === 2)>Sim, o tomador retém o ISSQN</option>
                                </x-campo>
                            </div>
                            <input type="hidden" name="retencao_issqn" value="1" :disabled="trib == '1'">
                            <div x-show="trib == '1' && (op == 1 || op == 3)" x-cloak class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <x-campo nome="deducao_tipo" tipo="select" rotulo="Será aplicado algum tipo de dedução/redução à base de cálculo do ISSQN?" x-model="ded" ajuda="Só vale para não optante ou ME/EPP com o ISSQN apurado pela NFS-e, e a prefeitura precisa permitir para o serviço.">
                                    <option value="none">Não</option>
                                    <option value="percentual" @selected(old('deducao_tipo', $emitente->deducao_tipo) === 'percentual')>Sim, por percentual (%)</option>
                                    <option value="valor" @selected(old('deducao_tipo', $emitente->deducao_tipo) === 'valor')>Sim, por valor (R$)</option>
                                </x-campo>
                                <div x-show="ded != 'none'" x-cloak>
                                    <x-campo nome="deducao_valor" obrigatorio rotulo="Valor da dedução/redução" tipo="number" step="0.01" :valor="$emitente->deducao_valor" />
                                </div>
                            </div>
                            <div class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-4 gap-4">
                                <div class="sm:col-span-4 space-y-2">
                                    <span class="campo-rotulo">Município da prestação do serviço</span>
                                    <div class="space-y-1.5">
                                        @foreach (['emitente' => 'Do emitente (a cidade da empresa)', 'tomador' => 'Do destinatário (a cidade do cliente de cada nota)', 'outro' => 'Outro (sempre em uma cidade específica)'] as $valor => $texto)
                                            <label class="flex items-start gap-2.5 text-sm cursor-pointer">
                                                <input type="radio" name="local_prestacao" value="{{ $valor }}" x-model="localPrest" class="mt-0.5 text-brand-600 focus:ring-brand-600">
                                                <span>{{ $texto }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div x-show="localPrest === 'outro'" x-cloak class="mt-1 grid grid-cols-[84px_minmax(0,1fr)] gap-2">
                                        <select name="uf_prestacao" x-model="ufPrest" @change="munPrest = ''; carregarCidadesPrest()" class="campo-entrada !mt-0" aria-label="Estado da prestação">
                                            <option value="">UF</option>
                                            @foreach (['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $sigla)<option>{{ $sigla }}</option>@endforeach
                                        </select>
                                        <input name="municipio_prestacao" list="lista-cidades-prest" x-model="munPrest" placeholder="Digite o nome da cidade" class="campo-entrada !mt-0" :disabled="!cidadesPrest.length" autocomplete="off" aria-label="Município da prestação">
                                        <datalist id="lista-cidades-prest"><template x-for="c in cidadesPrest" :key="c"><option :value="c"></option></template></datalist>
                                    </div>
                                    @error('municipio_prestacao')<p class="erro-campo" role="alert">{{ $message }}</p>@enderror
                                    <p class="campo-ajuda">Na maioria dos casos o serviço é prestado na cidade da empresa. Em "do destinatário", se o cliente não tiver município no cadastro, a nota usa a cidade da empresa.</p>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg bg-chao px-3.5 py-3 text-[13px] leading-relaxed text-ink-700">
                            Estes campos o próprio Emissor Nacional bloqueia, por isso não são enviados: exigibilidade do ISSQN suspensa, benefício municipal, código indicador da operação e IBS/CBS.
                        </div>
                    </fieldset>

                    {{-- Valor aproximado dos tributos: as formas que o Emissor Nacional oferece (as que a SEFIN aceita para o regime) --}}
                    <fieldset class="space-y-2 pt-2">
                        <legend class="text-sm font-semibold">Valor aproximado dos tributos</legend>
                        @foreach (\App\Models\Emitente::MODOS_TRIBUTOS_APROX as $valor => $rotulo)
                            <label class="flex items-start gap-2.5 text-sm cursor-pointer" x-show="modos.includes('{{ $valor }}')" x-cloak>
                                <input type="radio" name="modo_tributos_aprox" value="{{ $valor }}" :checked="modoAtual === '{{ $valor }}'" @change="modo = '{{ $valor }}'" class="mt-0.5 text-brand-600 focus:ring-brand-600">
                                <span>{{ $rotulo }}</span>
                            </label>
                        @endforeach
                        @error('modo_tributos_aprox')<p class="text-[13px] text-warn-800" role="alert">{{ $message }}</p>@enderror
                    </fieldset>

                    <div x-show="modoAtual === 'simples'" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <x-campo nome="aliquota_simples" obrigatorio rotulo="Alíquota do Simples Nacional (%)" tipo="number" step="0.01" :valor="$emitente->aliquota_simples" />
                    </div>
                    <div x-show="modoAtual === 'percentual' || modoAtual === 'monetario'" x-cloak class="space-y-2">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <x-campo nome="tributos_aprox[federal]" obrigatorio rotulo="Federal (%)" tipo="number" step="0.01" :valor="$trib['federal'] ?? null" />
                            <x-campo nome="tributos_aprox[estadual]" obrigatorio rotulo="Estadual (%)" tipo="number" step="0.01" :valor="$trib['estadual'] ?? null" />
                            <x-campo nome="tributos_aprox[municipal]" obrigatorio rotulo="Municipal (%)" tipo="number" step="0.01" :valor="$trib['municipal'] ?? null" />
                        </div>
                        <p class="campo-ajuda" x-show="modoAtual === 'monetario'">Os valores em R$ são preenchidos em cada nota. Nas notas importadas por planilha o valor é calculado com estes percentuais.</p>
                    </div>

                    {{-- Tributação federal padrão (só não optante) --}}
                    <div x-show="op == 1" x-cloak class="space-y-3 pt-2">
                        <h3 class="text-sm font-semibold">Tributação federal</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-campo nome="pis_cofins_cst" obrigatorio tipo="select" rotulo="Situação tributária do PIS/COFINS" x-model="cst">
                                @foreach (\App\Support\TabelasDps::CST_PIS_COFINS as $v => $r)
                                    <option value="{{ $v }}" @selected(old('pis_cofins_cst', $emitente->pis_cofins_cst ?: '00') == $v)>{{ $v }} - {{ $r }}</option>
                                @endforeach
                            </x-campo>
                            <x-campo nome="tp_ret_pis_cofins" obrigatorio tipo="select" rotulo="Tipo de retenção do PIS/COFINS/CSLL" ajuda="Os tipos com retenção de CSLL pedem o valor em cada nota (nota completa personalizada).">
                                @foreach ([0, 2] as $v)
                                    <option value="{{ $v }}" @selected(old('tp_ret_pis_cofins', $emitente->tp_ret_pis_cofins ?? 0) == $v)>{{ $v }} - {{ \App\Support\TabelasDps::RETENCAO_PIS_COFINS[$v] }}</option>
                                @endforeach
                            </x-campo>
                        </div>
                        <div x-show="cst !== '00'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-campo nome="aliquota_pis" obrigatorio rotulo="Alíquota do PIS (%)" tipo="number" step="0.01" :valor="$emitente->aliquota_pis" />
                            <x-campo nome="aliquota_cofins" obrigatorio rotulo="Alíquota da COFINS (%)" tipo="number" step="0.01" :valor="$emitente->aliquota_cofins" />
                            <p class="sm:col-span-2 campo-ajuda">A base de cálculo é o valor do serviço de cada nota.</p>
                        </div>
                        <p class="text-[13px] text-ink-600">IRRF, contribuições sociais retidas e contribuição previdenciária retida ficam sem preenchimento (se precisar, informe na nota completa personalizada).</p>
                    </div>
                </section>

                <section class="card p-5 sm:p-6 space-y-4">
                    <h2 class="text-lg font-semibold">4. Emissão</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
 <x-busca-servico class="sm:col-span-2" nome="codigo_servico_padrao" rotulo="Código de serviço padrão (opcional)" :valor="old('codigo_servico_padrao', $emitente->codigo_servico_padrao)" ajuda="Usado nas linhas da planilha sem código de serviço. Digite o código ou parte do nome da atividade (ex.: manutenção de programas) e escolha na lista." />
                        <x-campo nome="codigo_tributacao_municipal" obrigatorio list="lista-codigos-municipais" autocomplete="off" rotulo="Código complementar municipal (3 dígitos)" :valor="$emitente->codigo_tributacao_municipal" maxlength="3" mono x-bind:required="op != 2" x-bind:disabled="op == 2" ajuda="Obrigatório, exceto para MEI. É o número que a prefeitura acrescenta ao código do serviço: no código 01.07.01.001, é o 001. Na dúvida, veja como aparece no Emissor Nacional ou pergunte à contabilidade." />
                        <div class="sm:col-span-2 space-y-3 rounded-xl border border-gray-200 p-4">
                            <div>
                                <h3 class="text-sm font-semibold">Lista de códigos complementares da prefeitura</h3>
                                <p class="campo-ajuda">Opcional. Se a empresa usa mais de um código complementar, cadastre-os aqui (código de 3 dígitos e descrição, como no Emissor Nacional). Eles passam a aparecer como opções no campo acima e ao emitir uma nota completa.</p>
                            </div>
                            <datalist id="lista-codigos-municipais">
                                <template x-for="c in codigos" :key="c.codigo"><option :value="c.codigo" x-text="c.descricao"></option></template>
                            </datalist>
                            <template x-for="(c, i) in codigos" :key="i">
                                <div class="grid grid-cols-12 gap-2 items-start">
                                    <input :name="`codigos[${i}][codigo]`" x-model="c.codigo" maxlength="3" inputmode="numeric" placeholder="001" aria-label="Código" class="campo-entrada font-mono col-span-3 sm:col-span-2 !mt-0">
                                    <input :name="`codigos[${i}][descricao]`" x-model="c.descricao" placeholder="Descrição, como na prefeitura" aria-label="Descrição" class="campo-entrada col-span-9 sm:col-span-5 !mt-0">
                                    <input :name="`codigos[${i}][servico]`" x-model="c.servico" maxlength="6" inputmode="numeric" placeholder="Serviço (opcional)" aria-label="Código de serviço a que pertence" class="campo-entrada font-mono col-span-9 sm:col-span-4 !mt-0">
                                    <button type="button" @click="removerCodigo(i)" class="btn-excluir col-span-3 sm:col-span-1 !px-2" aria-label="Remover este código" title="Remover">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </template>
                            <p class="text-[13px] text-ink-600" x-show="!codigos.length">Nenhum código na lista.</p>
                            @php $erroCodigos = collect($errors->get('codigos*'))->flatten()->first(); @endphp
                            @if ($erroCodigos)<p class="erro-campo" role="alert">{{ $erroCodigos }}</p>@endif
                            <button type="button" class="btn btn-sm" @click="addCodigo()">Adicionar código</button>
                        </div>
                        <x-busca-servico class="sm:col-span-2" nome="codigo_nbs_padrao" rotulo="NBS padrão (opcional)" :valor="old('codigo_nbs_padrao', $emitente->codigo_nbs_padrao)"
                                         :url="route('nbs.buscar')" :tam="9" vazio="Selecione o item da NBS (código ou nome do serviço)" nenhum="Nenhum item da NBS encontrado. Tente outra palavra."
                                         ajuda="Usado quando a planilha não traz NBS. Confirme com a contabilidade." />
                        <div class="sm:col-span-2">
                            <label for="descricao_servico_padrao" class="campo-rotulo">Descrição padrão do serviço</label>
                            <textarea id="descricao_servico_padrao" name="descricao_servico_padrao" rows="2" class="campo-entrada" placeholder="Ex.: Manutenção de programas de computação.">{{ old('descricao_servico_padrao', $emitente->descricao_servico_padrao) }}</textarea>
                            <p class="campo-ajuda">Vai em toda nota cuja linha da planilha não tiver descrição própria (coluna R).</p>
                        </div>
                        <x-campo nome="email_copia" tipo="email" rotulo="E-mail da empresa (cópia das notas)" :valor="$emitente->email_copia" ajuda="Recebe cópia de toda NFS-e enviada ao cliente, para a empresa ter ciência." />
                        <div class="sm:col-span-2">
                            <input type="hidden" name="enviar_email_automatico" value="0">
                            <label class="flex items-start gap-2.5 text-sm cursor-pointer">
                                <input type="checkbox" name="enviar_email_automatico" value="1" class="mt-0.5 rounded text-brand-600 focus:ring-brand-600" @checked(old('enviar_email_automatico', $emitente->enviar_email_automatico))>
                                <span><b>Enviar a NFS-e ao cliente por e-mail assim que for emitida</b><span class="block text-[13px] text-ink-600">PDF e XML vão para os e-mails do cadastro do cliente. Só funciona em produção: em modo teste o envio é sempre manual.</span></span>
                            </label>
                        </div>
                        <div class="space-y-3">
                            <label class="flex items-start gap-2.5 text-sm cursor-pointer">
                                <input type="checkbox" name="informar_serie_dps" value="1" x-model="informarSerie" class="mt-0.5 rounded text-brand-600 focus:ring-brand-600">
                                <span><b>Informar série e número da DPS</b><span class="block text-[13px] text-ink-600">Como no Emissor Nacional. Desmarcado, a série é a 00001 e a numeração é toda automática.</span></span>
                            </label>
                            <div x-show="informarSerie" x-cloak>
                                <x-campo nome="serie_dps" obrigatorio rotulo="Série" :valor="$emitente->informar_serie_dps ? $emitente->serie_dps : '00001'" maxlength="5" mono ajuda="De 00001 a 49999. Já vem 00001; só mude se o seu contador pedir." x-bind:required="informarSerie" />
                            </div>
                        </div>
                        <x-campo nome="ambiente" obrigatorio tipo="select" rotulo="Ambiente" ajuda="Comece em teste. A produção só libera com o certificado cadastrado e depois de emitir uma nota em teste.">
                            <option value="2" @selected(old('ambiente', $emitente->ambiente ?? 2) == 2)>Teste (as notas não têm valor fiscal)</option>
                            <option value="1" @selected(old('ambiente', $emitente->ambiente ?? 2) == 1)>Produção (notas com valor fiscal)</option>
                        </x-campo>
                    </div>
                </section>

                <details class="card p-5 sm:p-6">
                    <summary class="cursor-pointer text-lg font-semibold">Ver todos os campos que vão na nota</summary>
                    <p class="text-[13px] text-ink-600 mt-1 mb-4">Os valores abaixo são os do cadastro salvo; depois de salvar, refletem as mudanças acima.</p>
                    <x-resumo-fiscal :grupos="$emitente->camposFiscais()" :legenda="false" />
                </details>

                <div class="flex items-center gap-4">
                    <button class="btn btn-primary btn-lg">Salvar{{ $semEmpresaAinda ? ' e continuar' : '' }}</button>
                    <a href="{{ route('emitentes.index') }}" class="btn btn-lg">Cancelar</a>
                </div>
            </form>

            <aside class="flex-[1_1_280px] min-w-0 space-y-4">
                @isset($numeracao)
                    <div class="card p-5 space-y-3">
                        <h2 class="text-base font-semibold">Numeração da DPS · série {{ $emitente->serie_dps }}</h2>
                        <p class="text-[13px] text-ink-700 leading-relaxed">Número de controle de cada nota enviada. O app cuida disso sozinho e nunca repete um número. O número que vale para o cliente é o da NFS-e, dado pelo ambiente nacional ao autorizar.</p>
                        @foreach ($numeracao as $ambiente => $n)
                            <div class="rounded-lg border border-gray-200 p-3 space-y-2">
                                <div class="text-sm"><b>{{ $n['rotulo'] }}</b><div class="text-ink-600 mt-0.5">último usado <b class="font-mono text-ink-900">{{ $n['ultimo'] ?: '—' }}</b>, próximo <b class="font-mono text-ink-900">{{ $n['proximo'] }}</b></div></div>
                                <form method="POST" action="{{ route('emitentes.numeracao', $emitente) }}" class="flex gap-2 items-end">
                                    @csrf
                                    <input type="hidden" name="ambiente" value="{{ $ambiente }}">
                                    <div class="flex-1">
                                        <label for="proximo-{{ $ambiente }}" class="campo-rotulo text-xs">Ajustar o próximo número</label>
                                        <input id="proximo-{{ $ambiente }}" name="proximo" type="number" min="{{ $n['maior_usado'] + 1 }}" value="{{ old('ambiente') == $ambiente ? old('proximo') : $n['proximo'] }}" class="campo-entrada font-mono !mt-0">
                                    </div>
                                    <button class="btn btn-sm">Ajustar</button>
                                </form>
                                @if (old('ambiente') == $ambiente) @error('proximo')<p class="text-[13px] text-warn-800" role="alert">{{ $message }}</p>@enderror @endif
                            </div>
                        @endforeach
                        <p class="text-[12px] text-ink-600">Só o dono ajusta. Não dá para voltar a um número que já foi usado.</p>

                        @if ($historicoDps->isNotEmpty())
                            <details>
                                <summary class="cursor-pointer text-sm font-medium">Números já usados</summary>
                                <div class="mt-2 text-[13px] divide-y divide-gray-100">
                                    @foreach ($historicoDps as $h)
                                        <div class="py-1.5 flex justify-between gap-2"><span class="font-mono">{{ $h->ambiente == 1 ? 'Prod.' : 'Teste' }} · DPS {{ $h->numero_dps }}</span><span class="text-ink-600">{{ $h->numero_nfse ? 'NFS-e nº '.$h->numero_nfse : $h->status->label() }}</span></div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                        @if ($ajustesDps->isNotEmpty())
                            <details>
                                <summary class="cursor-pointer text-sm font-medium">Ajustes manuais</summary>
                                <div class="mt-2 text-[13px] divide-y divide-gray-100">
                                    @foreach ($ajustesDps as $a)
                                        <div class="py-1.5">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('d/m/Y H:i') }} · {{ $a->ambiente == 1 ? 'produção' : 'teste' }}: próximo {{ $a->de }} → {{ $a->para }}</div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @endisset
                <div class="card p-5">
                    <h2 class="text-base font-semibold mb-2">Não tem o certificado?</h2>
                    <p class="text-sm leading-relaxed text-ink-700">É um arquivo .pfx ou .p12 (certificado A1) emitido para o CNPJ da empresa. Seu contador costuma ter.</p>
                </div>
                <div class="card p-5">
                    <h2 class="text-base font-semibold mb-2">Por que pedimos isso?</h2>
                    <p class="text-sm leading-relaxed text-ink-700">O certificado assina cada nota, como a assinatura do responsável. Ele é guardado criptografado e usado só para emitir as suas notas.</p>
                </div>
                <div class="rounded-xl bg-amber-50 border border-amber-200 p-5">
                    <h2 class="text-base font-semibold mb-2 text-amber-900">Comece em modo teste</h2>
                    <p class="text-sm leading-relaxed text-amber-900">As notas emitidas em teste não têm valor fiscal. Quando tudo estiver certo, você troca para produção.</p>
                </div>
            </aside>
        </div>
    </div>
</x-app-layout>
