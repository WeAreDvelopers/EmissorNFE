{{-- Campos do cliente, na ordem em que se preenche: dados, CEP (busca o endereço), endereço, e-mails e observações.
     Usado no cadastro novo ($criando) e na edição. --}}
@props(['tomador', 'podeEditar' => true, 'criando' => false])
<div class="grid grid-cols-1 sm:grid-cols-6 gap-4" x-data="enderecoCep(@js(route('cep.buscar', '__CEP__')))">
    @if ($criando)
        <div class="sm:col-span-3" x-data="validaDocumento">
            <x-campo nome="documento" rotulo="CPF ou CNPJ" :valor="$tomador->documento" mono required inputmode="numeric" maxlength="18" autocomplete="off" placeholder="000.000.000-00 ou 00.000.000/0000-00" x-mascara="doc"
                     x-on:blur="validar($event.target.value)" x-on:input="if (erro) validar($event.target.value)" x-bind:class="erro ? 'campo-invalido' : ''" x-bind:aria-invalid="erro ? 'true' : 'false'" ajuda="Não dá para trocar depois. Cada CPF/CNPJ só pode ser cadastrado uma vez." />
            <p class="erro-campo" role="alert" x-show="erro" x-cloak x-text="erro"></p>
        </div>
        <div class="hidden sm:block sm:col-span-3"></div>
    @endif

    {{-- Cadastro ativo/inativo: liga/desliga, sem relação com o CPF/CNPJ. Tomador não se exclui. --}}
    <div class="sm:col-span-6" x-data="{ ativo: @js((bool) old('ativo', $tomador->ativo ?? true)) }">
        <input type="hidden" name="ativo" :value="ativo ? 1 : 0">
        <div class="flex items-center gap-3">
            <button type="button" role="switch" :aria-checked="ativo" @click="ativo = ! ativo" @disabled(! $podeEditar) aria-label="Cadastro ativo ou inativo"
                    :class="ativo ? 'bg-brand-600' : 'bg-gray-400'" class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 disabled:opacity-60">
                <span :class="ativo ? 'translate-x-6' : 'translate-x-1'" class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform"></span>
            </button>
            <span class="text-sm font-semibold" x-text="ativo ? 'Cadastro ativo' : 'Cadastro inativo'"></span>
            <span class="text-[13px] text-ink-600" x-show="!ativo" x-cloak>Não aparece nas buscas ao emitir uma nota.</span>
        </div>
    </div>

    <x-campo nome="nome" rotulo="Razão social / nome" :valor="$tomador->nome" required :disabled="! $podeEditar" class="sm:col-span-3" />
    <x-campo nome="nome_fantasia" rotulo="Nome fantasia" :valor="$tomador->nome_fantasia" :disabled="! $podeEditar" class="sm:col-span-3" />

    <div class="sm:col-span-2">
        <x-campo nome="cep" obrigatorio rotulo="CEP" :valor="$tomador->cep" mono inputmode="numeric" maxlength="9" autocomplete="postal-code" placeholder="00000-000" x-mascara="cep" :disabled="! $podeEditar"
                 x-on:input="buscar($event.target.value)" />
        <p class="campo-ajuda" x-show="estado" x-cloak aria-live="polite">
            <span x-show="estado === 'buscando'">Buscando o endereço…</span>
            <span x-show="estado === 'ok'" class="text-ok-800">Endereço preenchido. Confira e informe o número.</span>
            <span x-show="estado === 'erro'" class="text-warn-900">CEP não localizado. Confirme com o tomador.</span>
        </p>
    </div>
    <x-campo nome="logradouro" obrigatorio rotulo="Logradouro" :valor="$tomador->logradouro" class="sm:col-span-4" :disabled="! $podeEditar" />
    <x-campo nome="numero" obrigatorio rotulo="Número" :valor="$tomador->numero" class="sm:col-span-2" :disabled="! $podeEditar" />
    <x-campo nome="complemento" rotulo="Complemento" :valor="$tomador->complemento" class="sm:col-span-4" :disabled="! $podeEditar" />
    <x-campo nome="bairro" obrigatorio rotulo="Bairro" :valor="$tomador->bairro" class="sm:col-span-2" :disabled="! $podeEditar" />
    {{-- Município pela tabela do IBGE (mesmo conceito do cadastro do Emitente): UF + cidade da lista; o código IBGE fica visível abaixo --}}
    <div class="sm:col-span-4" x-data="{
            uf: @js((string) old('uf', $tomador->uf)),
            cidade: @js((string) old('municipio', $tomador->municipio)),
            codigo: @js((string) old('codigo_municipio', $tomador->codigo_municipio)),
            cidades: [], carregado: '',
            norm(t) { return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); },
            async carregar() {
                const uf = String(this.uf || '').toUpperCase();
                if (uf.length !== 2) { this.cidades = []; this.carregado = ''; return; }
                if (this.carregado !== uf) {
                    try { const r = await fetch('https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + uf + '/municipios?orderBy=nome'); this.cidades = (await r.json()).map(c => ({ id: String(c.id), nome: c.nome })); this.carregado = uf; } catch (e) { this.cidades = []; }
                }
                this.escolher(this.cidade);
            },
            escolher(nome) {
                const c = this.cidades.find(x => this.norm(x.nome) === this.norm(nome));
                if (c) { this.codigo = c.id; this.cidade = c.nome; } else { this.codigo = ''; }
            },
            init() { this.carregar(); this.$watch('uf', () => this.carregar()); this.$watch('cidade', (v) => { if (this.cidades.length) this.escolher(v); }); }
         }">
        <div class="grid grid-cols-[minmax(0,1fr)_84px] gap-3">
            <div>
                <label for="municipio" class="campo-rotulo obrigatorio">Município</label>
                <input id="municipio" name="municipio" list="lista-cidades-tomador" x-model="cidade" @change="escolher($event.target.value)" autocomplete="off" placeholder="Digite o nome da cidade" class="campo-entrada {{ $errors->has('municipio') ? 'campo-invalido' : '' }}" @disabled(! $podeEditar)>
                <datalist id="lista-cidades-tomador"><template x-for="c in cidades" :key="c.id"><option :value="c.nome"></option></template></datalist>
                @error('municipio')<p class="erro-campo" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="uf" class="campo-rotulo obrigatorio">UF</label>
                <select id="uf" name="uf" :value="uf" @change="uf = $event.target.value" @input="uf = $event.target.value" class="campo-entrada {{ $errors->has('uf') ? 'campo-invalido' : '' }}" @disabled(! $podeEditar)>
                    <option value="">UF</option>
                    @foreach (['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $sigla)<option value="{{ $sigla }}" @selected(strtoupper((string) old('uf', $tomador->uf)) === $sigla)>{{ $sigla }}</option>@endforeach
                </select>
                @error('uf')<p class="erro-campo" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>
        <input type="hidden" name="codigo_municipio" :value="codigo">
        <p class="campo-ajuda">
            <span x-show="codigo">Código IBGE <b class="font-mono" x-text="codigo"></b></span>
            <span x-show="!codigo && cidade" x-cloak class="text-warn-900">Município não reconhecido: escolha a cidade na lista, conforme a UF.</span>
            <span x-show="!codigo && !cidade" x-cloak>Escolha a UF e depois a cidade.</span>
        </p>
    </div>
    <div class="hidden sm:block sm:col-span-2"></div>

    <x-campo nome="emails" rotulo="E-mails" :valor="implode(', ', $tomador->emails ?? [])" class="sm:col-span-6" :disabled="! $podeEditar" ajuda="Separe por vírgula. O primeiro é o principal e vai no XML da nota; o aviso de nota emitida vai para todos." />
    <div class="sm:col-span-6">
        <label for="observacoes" class="campo-rotulo">Observações</label>
        <textarea id="observacoes" name="observacoes" rows="3" class="campo-entrada" @disabled(! $podeEditar)>{{ old('observacoes', $tomador->observacoes) }}</textarea>
    </div>
</div>
