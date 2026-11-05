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
            municipio: @js(old('codigo_municipio', $emitente->codigo_municipio)), cidade: null, uf: '', cidades: [],
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
            escolherCidade(nome) { const c = this.cidades.find(x => x.nome.toLowerCase() === nome.toLowerCase()); if (c) { this.municipio = c.id; this.cidade = c.nome; } },
            async init() {
                if (this.municipio) { try { const r = await fetch('https://servicodados.ibge.gov.br/api/v1/localidades/municipios/' + this.municipio); const j = await r.json(); if (j && j.nome) this.cidade = j.nome + ' · ' + j.microrregiao.mesorregiao.UF.sigla; } catch (e) {} }
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
                    @if ($emitente->certificado_validade)
                        <p class="text-sm text-ink-700">Atual: válido até {{ $emitente->certificado_validade->format('d/m/Y') }}. Envie outro só para substituir.</p>
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

                        <div>
                            <span class="campo-rotulo">Cidade</span>
                            <div class="mt-1 grid grid-cols-[84px_minmax(0,1fr)] gap-2">
                                <select x-model="uf" @change="carregarCidades()" class="campo-entrada !mt-0" aria-label="Estado">
                                    <option value="">UF</option>
                                    @foreach (['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $sigla)<option>{{ $sigla }}</option>@endforeach
                                </select>
                                <input list="lista-cidades" placeholder="Digite o nome da cidade" @change="escolherCidade($event.target.value)" class="campo-entrada !mt-0" :disabled="!cidades.length" aria-label="Cidade">
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
