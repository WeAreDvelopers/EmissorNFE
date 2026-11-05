<x-app-layout titulo="Editar lote">
    <div class="max-w-[1480px] mx-auto px-4 sm:px-8 py-7 space-y-5"
         x-data="grade(@js($linhas), {
            salvarUrl: '{{ route('importacoes.linhas.salvar', $importacao) }}',
            conferirUrl: '{{ route('importacoes.conferir', $importacao) }}',
            csrf: '{{ csrf_token() }}',
            hoje: '{{ now()->format('d/m/Y') }}'
         })"
         @beforeunload.window="if (sujo) { $event.preventDefault(); $event.returnValue = ''; }">

        <div class="space-y-2.5">
            <a href="{{ route('importacoes.index') }}" class="link text-sm">← Importações</a>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold">{{ $importacao->reprocessadaDe ? 'Reprocessar lote' : 'Editar lote' }}</h1>
                    <p class="mt-1 text-[15px] text-ink-700">
                        {{ $importacao->arquivo_nome }} · {{ $importacao->emitente->razao_social }}
                        @if ($importacao->reprocessadaDe)· cópia de <a class="link" href="{{ route('importacoes.show', $importacao->reprocessadaDe) }}">{{ $importacao->reprocessadaDe->arquivo_nome }}</a>@endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm" :class="sujo ? 'text-amber-900 font-medium' : 'text-ink-600'" role="status" x-text="status"></span>
                    <button type="button" class="btn" @click="salvar(false)" :disabled="salvando || !sujo">Salvar</button>
                    <button type="button" class="btn btn-primary" @click="salvar(true)" :disabled="salvando || linhasPreenchidas() === 0"><span x-show="!salvando">Salvar e revisar →</span><span x-show="salvando" x-cloak>Salvando…</span></button>
                </div>
            </div>
        </div>

        @if ($importacao->reprocessadaDe)
            <div class="rounded-xl bg-info-100 border border-info-500/40 px-4 py-3 text-sm text-info-800">
                <b>Isto cria notas novas.</b> As notas já emitidas do lote original continuam como estão: nada é alterado ou cancelado lá. Edite, adicione ou remova linhas à vontade; antes de enviar você ainda confere o custo.
            </div>
        @endif

        <div x-show="erroGeral" x-cloak class="rounded-lg bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900" role="alert" x-text="erroGeral"></div>

        <section class="card overflow-hidden">
            {{-- Barra de ferramentas --}}
            <div class="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-b border-linha bg-chao/60">
                <button type="button" class="btn btn-sm" @click="adicionar()">+ Adicionar linha</button>
                <div class="flex items-center gap-2 text-sm">
                    <label for="comp-todas" class="text-ink-700">Competência de todas:</label>
                    <input id="comp-todas" x-model="competenciaTodas" placeholder="dd/mm/aaaa" class="w-[120px] min-h-[40px] rounded-lg border-gray-400 text-sm font-mono focus:border-brand-600 focus:ring-brand-600">
                    <button type="button" class="btn btn-sm" @click="aplicarCompetencia()" :disabled="!competenciaTodas">Aplicar</button>
                </div>
                <span class="text-xs text-ink-600 ms-auto hidden lg:block">Dica: cole dados copiados do Excel em qualquer célula. As setas ↑ ↓ mudam de linha.</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1240px] table-fixed text-sm border-collapse">
                    <colgroup>
                        <col class="w-[56px]"><col class="w-[160px]"><col class="w-[22%]"><col class="w-[19%]"><col class="w-[86px]"><col class="w-[112px]"><col><col class="w-[112px]"><col class="w-[116px]"><col class="w-[84px]">
                    </colgroup>
                    <thead class="text-left text-xs uppercase tracking-wide text-ink-600 bg-chao/60 border-b border-linha">
                        <tr>
                            <th class="ps-4 py-2.5 font-medium">#</th>
                            <th class="px-3 py-2.5 font-medium">CPF/CNPJ</th>
                            <th class="px-3 py-2.5 font-medium">Cliente</th>
                            <th class="px-3 py-2.5 font-medium">E-mail</th>
                            <th class="px-3 py-2.5 font-medium">Serviço</th>
                            <th class="px-3 py-2.5 font-medium">NBS</th>
                            <th class="px-3 py-2.5 font-medium">Descrição</th>
                            <th class="px-3 py-2.5 font-medium text-right">Valor (R$)</th>
                            <th class="px-3 py-2.5 font-medium">Competência</th>
                            <th class="pe-4 py-2.5 font-medium"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>

                    <template x-for="item in pagina()" :key="item.linha._k">
                        <tbody>
                            <tr :class="item.linha.erros.length ? 'bg-warn-50' : 'hover:bg-gray-50'" class="border-b border-gray-200 align-middle">
                                <td class="ps-4 py-1 font-mono text-xs text-ink-600" x-text="item.i + 1"></td>
                                <template x-for="(campo, c) in campos" :key="campo">
                                    <td class="p-0">
                                        <input :value="item.linha[campo]" :data-r="item.i" :data-c="c"
                                               @input="item.linha[campo] = $event.target.value; marcarSujo()"
                                               @keydown.arrow-down.prevent="mover($event, item.i, c, 1)"
                                               @keydown.arrow-up.prevent="mover($event, item.i, c, -1)"
                                               @paste="colar($event, item.i, c)"
                                               :aria-label="rotulos[c] + ' da linha ' + (item.i + 1)"
                                               :class="[(campo === 'valor') ? 'text-right font-mono' : '', ['tomador_documento','codigo_servico','codigo_nbs','data_competencia'].includes(campo) ? 'font-mono text-[13px]' : '', erroNoCampo(item.linha, campo) ? 'bg-warn-100 ring-1 ring-inset ring-warn-600' : '']"
                                               class="block w-full min-h-[42px] px-3 bg-transparent border-0 text-sm focus:bg-white focus:ring-2 focus:ring-inset focus:ring-brand-600 focus:outline-none">
                                    </td>
                                </template>
                                <td class="pe-3 text-right whitespace-nowrap">
                                    <button type="button" class="w-8 h-8 inline-flex items-center justify-center rounded-md text-ink-600 hover:bg-gray-100" @click="duplicar(item.i)" title="Duplicar linha" :aria-label="'Duplicar a linha ' + (item.i + 1)">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h9"/></svg>
                                    </button>
                                    <button type="button" class="w-8 h-8 inline-flex items-center justify-center rounded-md text-warn-800 hover:bg-warn-100" @click="remover(item.i)" title="Remover linha" :aria-label="'Remover a linha ' + (item.i + 1)">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>
                                    </button>
                                </td>
                            </tr>
                            <tr x-show="item.linha.erros.length" class="bg-warn-50 border-b border-gray-200">
                                <td></td><td colspan="9" class="px-3 pb-2 text-[13px] text-warn-800" x-text="item.linha.erros.join(' ')"></td>
                            </tr>
                        </tbody>
                    </template>

                    <tbody x-show="rows.length === 0" x-cloak>
                        <tr><td colspan="10" class="px-6 py-12 text-center text-ink-700">Nenhuma linha. Clique em "Adicionar linha" ou cole dados do Excel.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-3 border-t border-linha text-sm">
                <button type="button" class="btn btn-sm" @click="adicionar()">+ Adicionar linha</button>
                <div class="flex items-center gap-3" x-show="totalPaginas() > 1" x-cloak>
                    <button type="button" class="btn btn-sm" @click="irPara(pag - 1)" :disabled="pag === 0">Anterior</button>
                    <span class="text-ink-700">Página <b x-text="pag + 1"></b> de <b x-text="totalPaginas()"></b></span>
                    <button type="button" class="btn btn-sm" @click="irPara(pag + 1)" :disabled="pag >= totalPaginas() - 1">Próxima</button>
                </div>
                <span class="text-ink-700"><b x-text="linhasPreenchidas()"></b> linhas · <b x-text="comProblema()" :class="comProblema() ? 'text-warn-800' : ''"></b> com problema</span>
            </div>
        </section>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('grade', (iniciais, cfg) => ({
                campos: ['tomador_documento', 'tomador_nome', 'tomador_email', 'codigo_servico', 'codigo_nbs', 'descricao', 'valor', 'data_competencia'],
                rotulos: ['CPF ou CNPJ', 'Cliente', 'E-mail', 'Serviço', 'NBS', 'Descrição', 'Valor', 'Competência'],
                camposDeErro: { tomador_documento: /cpf|cnpj/i, tomador_nome: /nome|razão/i, tomador_email: /e-?mail/i, codigo_servico: /c[oó]digo de servi/i, codigo_nbs: /nbs/i, descricao: /descri/i, valor: /valor/i, data_competencia: /compet|data/i },
                rows: [], pag: 0, tamanho: 100, sujo: false, salvando: false, erroGeral: null, status: 'Sem alterações', competenciaTodas: '', _seq: 0,

                init() {
                    this.rows = iniciais.map(l => this.preparar(l));
                    if (this.rows.length === 0) this.adicionar();
                    this.sujo = false;
                },
                preparar(l) { return { _k: ++this._seq, id: l.id ?? null, erros: l.erros ?? [], ...Object.fromEntries(this.campos.map(c => [c, l[c] ?? ''])) }; },
                vazia() { return this.preparar({}); },

                pagina() { const ini = this.pag * this.tamanho; return this.rows.slice(ini, ini + this.tamanho).map((linha, k) => ({ linha, i: ini + k })); },
                totalPaginas() { return Math.max(1, Math.ceil(this.rows.length / this.tamanho)); },
                irPara(p) { this.pag = Math.min(Math.max(p, 0), this.totalPaginas() - 1); },
                linhasPreenchidas() { return this.rows.filter(r => this.campos.some(c => String(r[c] ?? '').trim() !== '')).length; },
                comProblema() { return this.rows.filter(r => r.erros.length).length; },
                erroNoCampo(linha, campo) { return linha.erros.some(e => this.camposDeErro[campo].test(e)); },

                marcarSujo() { this.sujo = true; this.status = 'Alterações não salvas'; },

                adicionar() { this.rows.push(this.vazia()); this.irPara(this.totalPaginas() - 1); this.marcarSujo(); this.$nextTick(() => this.focar(this.rows.length - 1, 0)); },
                duplicar(i) { const { _k, id, erros, ...dados } = this.rows[i]; this.rows.splice(i + 1, 0, this.preparar(dados)); this.marcarSujo(); this.$nextTick(() => this.focar(i + 1, 0)); },
                remover(i) { this.rows.splice(i, 1); if (this.pag >= this.totalPaginas()) this.irPara(this.totalPaginas() - 1); this.marcarSujo(); },

                aplicarCompetencia() { this.rows.forEach(r => { if (this.campos.some(c => String(r[c] ?? '').trim() !== '')) r.data_competencia = this.competenciaTodas.trim(); }); this.marcarSujo(); },

                focar(i, c) { this.irPara(Math.floor(i / this.tamanho)); this.$nextTick(() => { const el = this.$root.querySelector(`input[data-r="${i}"][data-c="${c}"]`); if (el) { el.focus(); el.select(); } }); },
                mover(e, i, c, d) { const prox = i + d; if (prox >= 0 && prox < this.rows.length) this.focar(prox, c); },

                // Cola do Excel: várias linhas/colunas de uma vez, a partir da célula atual
                colar(e, i, c) {
                    const texto = (e.clipboardData || window.clipboardData).getData('text');
                    if (!/[\t\n]/.test(texto.replace(/\n$/, ''))) return;     // texto simples: deixa o navegador colar normal
                    e.preventDefault();
                    const linhas = texto.replace(/\r/g, '').replace(/\n$/, '').split('\n').map(l => l.split('\t'));
                    linhas.forEach((cols, dr) => {
                        while (i + dr >= this.rows.length) this.rows.push(this.vazia());
                        cols.forEach((valor, dc) => { const campo = this.campos[c + dc]; if (campo) this.rows[i + dr][campo] = valor.trim(); });
                    });
                    this.marcarSujo();
                },

                async salvar(irParaConferir) {
                    this.salvando = true; this.erroGeral = null;
                    try {
                        const r = await fetch(cfg.salvarUrl, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
                            body: JSON.stringify({ linhas: this.rows.map(({ _k, erros, ...dados }) => dados) }),
                        });
                        const j = await r.json().catch(() => ({}));
                        if (!r.ok) { this.erroGeral = j.message || 'Não foi possível salvar agora. Tente de novo.'; this.salvando = false; return; }
                        this.rows = j.linhas.map(l => this.preparar(l));
                        if (this.rows.length === 0) this.rows.push(this.vazia());
                        this.irPara(this.pag);
                        this.sujo = false;
                        this.status = j.invalidas ? `Salvo · ${j.invalidas} ${j.invalidas === 1 ? 'linha com problema' : 'linhas com problema'}` : 'Salvo';
                        if (irParaConferir) { window.location.href = cfg.conferirUrl; return; }
                    } catch (e) {
                        this.erroGeral = 'Sem conexão com o servidor. Suas alterações continuam na tela; tente salvar de novo.';
                    }
                    this.salvando = false;
                },
            }));
        });
    </script>
</x-app-layout>
