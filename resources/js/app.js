import './bootstrap';
import './ajax';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Campo de código de serviço no estilo select2: parece um select ("01.07.01 - Descrição"), e ao clicar
// abre uma lista com busca na lista nacional (cadastro da empresa, nota manual e correção de nota)
Alpine.data('buscaServico', (inicial, url, tam = 6) => ({
    texto: inicial || '',      // código escolhido: é o que vai no formulário
    descricao: '',
    termo: '',
    itens: [],
    aberto: false,
    indice: 0,
    ctl: null,
    fmt(c) { return tam === 6 && /^\d{6}$/.test(c) ? `${c.slice(0, 2)}.${c.slice(2, 4)}.${c.slice(4)}` : c; },
    ok(c) { return new RegExp(`^\\d{${tam}}$`).test(c); },
    init() { if (this.ok(this.texto)) this.carregarDescricao(); },
    async consultar(q) {
        try {
            this.ctl?.abort(); this.ctl = new AbortController();
            const r = await fetch(`${url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, signal: this.ctl.signal });
            return await r.json();
        } catch (e) { return null; }
    },
    async carregarDescricao() {
        const itens = await this.consultar(this.texto);
        this.descricao = itens?.find(i => i.codigo === this.texto)?.descricao || '';
    },
    // Código vindo de fora (padrões da empresa na nota manual)
    definir(codigo) { this.texto = codigo || ''; this.descricao = ''; if (this.ok(this.texto)) this.carregarDescricao(); },
    async abrir() {
        this.aberto = true; this.termo = ''; this.indice = 0;
        this.$nextTick(() => this.$refs.busca?.focus());
        await this.buscar();
    },
    async buscar() {
        const itens = await this.consultar(this.termo.trim());
        if (itens) { this.itens = itens; this.indice = 0; }
    },
    mover(passo) {
        if (!this.itens.length) return;
        this.indice = (this.indice + passo + this.itens.length) % this.itens.length;
        this.$nextTick(() => this.$refs.lista?.querySelectorAll('[role=option]')[this.indice]?.scrollIntoView({ block: 'nearest' }));
    },
    escolher(i) { if (!i) return; this.texto = i.codigo; this.descricao = i.descricao; this.aberto = false; this.$refs.gatilho?.focus(); },
    limpar() { this.texto = ''; this.descricao = ''; this.aberto = false; },
}));

// Emissão manual: padrões da empresa vêm marcados; desmarcar libera a edição só nesta nota
Alpine.data('notaManual', (cfg) => ({
    ...cfg,
    q: '', clientes: [], abertoCli: false, ctlCli: null,
    get p() { return this.padroes[this.emitente] || { codigo: '', nbs: '', descricao: '' }; },
    get temPadrao() { return !!(this.p.codigo || this.p.nbs || this.p.descricao); },
    aplicarPadrao() {
        this.$dispatch('definir-nbs', this.p.nbs);
        this.descricao = this.p.descricao;
        this.$dispatch('definir-servico', this.p.codigo);
    },
    // Dados fiscais da empresa que "Carregar todos os campos" libera para ajuste só nesta nota
    get f() { return this.p.fiscal || { op: 0 }; },
    padroesFiscais() {
        const f = this.f;
        return {
            mun_prestacao: f.mun_prestacao || '', uf_prestacao: f.uf_prestacao || '', cod_trib_mun: f.cod_trib_mun || '',
            regime_especial: f.regime_especial ?? '0', retencao: '1', aliquota_simples: f.aliquota_simples || '',
            trib_federal: f.trib_federal || '', trib_estadual: f.trib_estadual || '', trib_municipal: f.trib_municipal || '',
            trib_issqn: '1', tp_imunidade: '', deducao_tipo: 'none', deducao_valor: '',
            pis_cst: f.pis_cst || '00', pis_ret: f.pis_ret || '0', pis_bc: '', pis_aliq_pis: f.pis_aliq_pis || '', pis_aliq_cofins: f.pis_aliq_cofins || '',
            ret_irrf: '', ret_csll: '', ret_cp: '',
            modo_tributos_aprox: f.modo || '', val_federal: '', val_estadual: '', val_municipal: '',
        };
    },
    alternarTodosCampos() {
        this.avancado = !this.avancado;
        if (this.avancado) {
            this.usarPadrao = false;            // serviço também fica livre para alterar
        } else {
            this.adv = this.padroesFiscais();   // volta aos padrões da empresa
            if (this.temPadrao) this.usarPadrao = true;
        }
    },
    init() {
        this.$watch('emitente', () => {
            this.adv = this.padroesFiscais();   // outra empresa: os valores do formulário seguem o cadastro dela
            if (!this.temPadrao) this.usarPadrao = false; else if (this.usarPadrao) this.aplicarPadrao();
        });
        this.$watch('usarPadrao', (v) => { if (v) this.aplicarPadrao(); });
        // Imunidade ou não incidência: sem regime especial, retenção nem dedução (regras E0172, E0580 e E0435 da SEFIN)
        this.$watch('adv.trib_issqn', (v) => {
            if (v !== '1') { this.adv.regime_especial = '0'; this.adv.retencao = '1'; this.adv.deducao_tipo = 'none'; this.adv.deducao_valor = ''; }
            if (v !== '2') this.adv.tp_imunidade = '';
        });
        this.$watch('adv.pis_ret', (v) => { if (v === '0') this.adv.ret_csll = ''; });   // com retenção 0 a CSLL retida é proibida (E0720)
    },
    async buscarCliente() {
        const q = this.q.trim();
        if (q.length < 2) { this.clientes = []; this.abertoCli = false; return; }
        try {
            this.ctlCli?.abort(); this.ctlCli = new AbortController();
            const r = await fetch(`${this.urlClientes}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, signal: this.ctlCli.signal });
            this.clientes = await r.json();
            this.abertoCli = true;
        } catch (e) { /* busca cancelada ou sem rede: mantém o que estava */ }
    },
    escolherCliente(c) {
        this.doc = c.documento; this.nome = c.nome; this.email = c.email || '';
        this.q = ''; this.abertoCli = false; this.semTomador = false;
    },
}));

// Máscara de campo: x-mascara="doc" (CPF até 11 dígitos, CNPJ a partir do 12º, trocando sozinha) ou x-mascara="cep".
// Só muda o que aparece: o servidor sempre tira a pontuação. Mantém o cursor no lugar ao editar no meio do texto.
const MASCARAS = {
    doc: (d) => {
        d = d.slice(0, 14);
        if (d.length <= 11) {
            return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2');
        }
        return d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
    },
    cep: (d) => d.slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2'),
};
Alpine.directive('mascara', (el, { expression }) => {
    const fmt = MASCARAS[expression];
    if (!fmt) return;
    const aplicar = () => {
        const antes = el.value;
        const cursor = el.selectionStart ?? antes.length;
        const digitosAntes = antes.slice(0, cursor).replace(/\D/g, '').length;
        const novo = fmt(antes.replace(/\D/g, ''));
        if (novo === antes) return;
        el.value = novo;
        if (document.activeElement === el) {
            let pos = 0, vistos = 0;
            while (pos < novo.length && vistos < digitosAntes) { if (/\d/.test(novo[pos])) vistos++; pos++; }
            el.setSelectionRange(pos, pos);
        }
    };
    el.addEventListener('input', aplicar);
    aplicar();   // valor que já vem preenchido (edição, ou volta de erro de validação)
});

// Endereço do cliente: ao digitar o CEP completo, busca e preenche logradouro, bairro, município e UF
Alpine.data('enderecoCep', (url) => ({
    estado: '',   // '' | buscando | ok | erro
    ctl: null,
    campo(nome) { return this.$root.querySelector(`[name="${nome}"]`); },
    async buscar(valor) {
        const cep = (valor || '').replace(/\D/g, '');
        if (cep.length !== 8) { this.estado = ''; return; }
        this.estado = 'buscando';
        try {
            this.ctl?.abort(); this.ctl = new AbortController();
            const r = await fetch(url.replace('__CEP__', cep), { headers: { Accept: 'application/json' }, signal: this.ctl.signal });
            if (!r.ok) { this.estado = 'erro'; return; }
            const d = await r.json();
            for (const n of ['logradouro', 'bairro', 'municipio', 'uf']) {
                const el = this.campo(n);
                if (el && d[n]) { el.value = d[n]; el.dispatchEvent(new Event('input', { bubbles: true })); }
            }
            this.estado = 'ok';
            this.campo(d.logradouro ? 'numero' : 'logradouro')?.focus();
        } catch (e) { if (e.name !== 'AbortError') this.estado = 'erro'; }
    },
}));

Alpine.start();
