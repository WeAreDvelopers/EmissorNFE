<x-app-layout :titulo="$emitente->exists ? 'Editar empresa' : 'Cadastrar empresa'">
    @php
        $op = old('op_simples_nacional', $emitente->op_simples_nacional ?? 3);
        $trib = $emitente->tributos_aprox ?? [];
        $novo = ! $emitente->exists;
        $semEmpresaAinda = $novo && \App\Models\Emitente::doesntExist();
    @endphp

    <div class="max-w-[1120px] mx-auto px-4 sm:px-8 py-8 space-y-6"
         x-data="{
            op: '{{ $op }}',
            cnpj: @js(old('cnpj', \App\Support\Documento::formatar($emitente->cnpj))),
            razao: @js(old('razao_social', $emitente->razao_social)),
            lendo: false, lido: false, erro: null, validade: null, vencido: false,
            municipio: @js(old('codigo_municipio', $emitente->codigo_municipio)), cidade: null, cidadeNome: '', uf: '', cidades: [],
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
                    else { this.lido = true; if (j.cnpj) this.cnpj = j.cnpj; if (j.razao_social) this.razao = j.razao_social; this.validade = j.valido_ate; this.vencido = j.vencido; }
                } catch (e) { this.erro = 'Não foi possível ler o certificado agora. Tente de novo.'; }
                this.lendo = false;
            },
            async carregarCidades() {
                if (!this.uf) { this.cidades = []; return; }
                try { const r = await fetch('https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + this.uf + '/municipios?orderBy=nome'); this.cidades = (await r.json()).map(c => ({ id: String(c.id), nome: c.nome })); } catch (e) { this.cidades = []; }
            },
            escolherCidade(nome) { const c = this.cidades.find(x => x.nome.toLowerCase() === nome.toLowerCase()); if (c) { this.municipio = c.id; this.cidade = c.nome + ' · ' + this.uf; this.cidadeNome = c.nome; } },
            async init() {
                if (this.municipio) { try { const r = await fetch('https://servicodados.ibge.gov.br/api/v1/localidades/municipios/' + this.municipio); const j = await r.json(); if (j && j.nome) { const sigla = j.microrregiao.mesorregiao.UF.sigla; this.cidade = j.nome + ' · ' + sigla; this.uf = sigla; this.cidadeNome = j.nome; await this.carregarCidades(); } } catch (e) {} }
            }
         }">

        <div class="space-y-2">
            <a href="{{ route('emitentes.index') }}" class="link text-sm">← Empresas que emitem</a>
            <h1 class="text-3xl font-semibold">{{ $semEmpresaAinda ? 'Vamos cadastrar a sua empresa' : ($novo ? 'Cadastrar empresa' : 'Editar empresa') }}</h1>
            <p class="text-[15px] text-ink-700">O certificado digital A1 assina as suas notas. A gente lê os dados dele para você não digitar nada.</p>
        </div>

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
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="certificado" class="campo-rotulo">Arquivo do certificado</label>
                            <input id="certificado" name="certificado" type="file" accept=".pfx,.p12" x-ref="certificado" @change="lido = false; erro = null" class="campo-entrada py-2" @required($novo)>
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
                        <x-campo nome="cnpj" rotulo="CNPJ" mono x-model="cnpj" ajuda="Vem do certificado quando possível." required />
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
                        <x-campo nome="op_simples_nacional" tipo="select" rotulo="Situação no Simples Nacional" x-model="op" ajuda="Não sabe? Pergunte ao seu contador ou veja no cartão CNPJ.">
                            <option value="1">Não optante</option>
                            <option value="2">MEI</option>
                            <option value="3">ME/EPP optante</option>
                        </x-campo>
                        <x-campo nome="regime_especial" tipo="select" rotulo="Regime especial de tributação">
                            @foreach ([0 => 'Nenhum', 1 => 'Ato cooperado', 2 => 'Estimativa', 3 => 'Sociedade de profissionais', 4 => 'Cooperativa', 5 => 'MEI', 6 => 'ME/EPP', 9 => 'Outros'] as $v => $r)
                                <option value="{{ $v }}" @selected(old('regime_especial', $emitente->regime_especial ?? 0) == $v)>{{ $r }}</option>
                            @endforeach
                        </x-campo>
                    </div>

                    <div x-show="op == 3" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-campo nome="regime_apuracao_sn" tipo="select" rotulo="Regime de apuração do Simples">
                            @foreach ([1 => 'Tributos federais e municipal pelo Simples', 2 => 'Federais pelo Simples, ISSQN pela NFS-e', 3 => 'Federais e municipal pela NFS-e'] as $v => $r)
                                <option value="{{ $v }}" @selected(old('regime_apuracao_sn', $emitente->regime_apuracao_sn ?? 1) == $v)>{{ $r }}</option>
                            @endforeach
                        </x-campo>
                        <x-campo nome="aliquota_simples" rotulo="Alíquota do Simples (%)" tipo="number" step="0.01" :valor="$emitente->aliquota_simples" />
                    </div>

                    <div x-show="op == 1" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <x-campo nome="tributos_aprox[federal]" rotulo="Tributos aprox. federal (%)" tipo="number" step="0.01" :valor="$trib['federal'] ?? null" />
                        <x-campo nome="tributos_aprox[estadual]" rotulo="Estadual (%)" tipo="number" step="0.01" :valor="$trib['estadual'] ?? null" />
                        <x-campo nome="tributos_aprox[municipal]" rotulo="Municipal (%)" tipo="number" step="0.01" :valor="$trib['municipal'] ?? null" />
                    </div>
                </section>

                <section class="card p-5 sm:p-6 space-y-4">
                    <h2 class="text-lg font-semibold">4. Emissão</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
 <x-campo nome="codigo_servico_padrao" rotulo="Código de serviço padrão (opcional)" :valor="$emitente->codigo_servico_padrao" maxlength="6" mono ajuda="Usado nas linhas da planilha sem código de serviço. Empresas com vários serviços podem trazer o código na planilha. Ex.: 010701 (manutenção de programas de computação)." />
                        <x-campo nome="codigo_nbs_padrao" rotulo="NBS padrão (opcional)" :valor="$emitente->codigo_nbs_padrao" maxlength="9" mono ajuda="9 dígitos. Usado quando a planilha não traz NBS. Confirme com a contabilidade." />
                        <div class="sm:col-span-2">
                            <label for="descricao_servico_padrao" class="campo-rotulo">Descrição padrão do serviço</label>
                            <textarea id="descricao_servico_padrao" name="descricao_servico_padrao" rows="2" class="campo-entrada" placeholder="Ex.: Manutenção de programas de computação.">{{ old('descricao_servico_padrao', $emitente->descricao_servico_padrao) }}</textarea>
                            <p class="campo-ajuda">Vai em toda nota cuja linha da planilha não tiver descrição própria (coluna R).</p>
                        </div>
                        <x-campo nome="serie_dps" rotulo="Série" :valor="$emitente->serie_dps" maxlength="5" mono ajuda="Já vem pronta (00001). Só mude se o seu contador pedir." required />
                        <x-campo nome="ambiente" tipo="select" rotulo="Ambiente" ajuda="Comece em teste. A produção só libera depois de emitir uma nota em teste.">
                            <option value="2" @selected(old('ambiente', $emitente->ambiente ?? 2) == 2)>Teste (as notas não têm valor fiscal)</option>
                            <option value="1" @selected(old('ambiente', $emitente->ambiente ?? 2) == 1)>Produção (notas com valor fiscal)</option>
                        </x-campo>
                    </div>
                </section>

                <div class="flex items-center gap-4">
                    <button class="btn btn-primary btn-lg">Salvar{{ $semEmpresaAinda ? ' e continuar' : '' }}</button>
                    <a href="{{ route('emitentes.index') }}" class="text-sm text-ink-700">Cancelar</a>
                </div>
            </form>

            <aside class="flex-[1_1_280px] min-w-0 space-y-4">
                @isset($numeracao)
                    <div class="card p-5 space-y-3">
                        <h2 class="text-base font-semibold">Numeração da DPS · série {{ $emitente->serie_dps }}</h2>
                        <p class="text-[13px] text-ink-700 leading-relaxed">Número de controle de cada nota enviada. O app cuida disso sozinho e nunca repete um número. O número que vale para o cliente é o da NFS-e, dado pelo ambiente nacional ao autorizar.</p>
                        @foreach ($numeracao as $ambiente => $n)
                            <div class="rounded-lg border border-gray-200 p-3 space-y-2">
                                <div class="flex justify-between gap-2 text-sm"><b>{{ $n['rotulo'] }}</b><span class="text-ink-600">último usado <b class="font-mono text-ink-900">{{ $n['ultimo'] ?: '—' }}</b> · próximo <b class="font-mono text-ink-900">{{ $n['proximo'] }}</b></span></div>
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
