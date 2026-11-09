import './bootstrap';
import './ajax';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Campo de código de serviço com busca na lista nacional (cadastro da empresa e correção de nota)
Alpine.data('buscaServico', (inicial, url) => ({
    texto: inicial || '',
    descricao: '',
    itens: [],
    aberto: false,
    ctl: null,
    init() { if (/^\d{6}$/.test(this.texto)) this.buscar(true); },
    async buscar(silencioso = false) {
        const q = this.texto.trim();
        if (!q) { this.itens = []; this.aberto = false; this.descricao = ''; return; }
        try {
            this.ctl?.abort(); this.ctl = new AbortController();
            const r = await fetch(`${url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, signal: this.ctl.signal });
            this.itens = await r.json();
        } catch (e) { return; }
        const exato = this.itens.find(i => i.codigo === q);
        this.descricao = exato ? exato.descricao : '';
        this.aberto = !silencioso && !exato;
    },
    escolher(i) { this.texto = i.codigo; this.descricao = i.descricao; this.aberto = false; },
}));

// Emissão manual: padrões da empresa vêm marcados; desmarcar libera a edição só nesta nota
Alpine.data('notaManual', (cfg) => ({
    ...cfg,
    q: '', clientes: [], abertoCli: false, ctlCli: null,
    get p() { return this.padroes[this.emitente] || { codigo: '', nbs: '', descricao: '' }; },
    get temPadrao() { return !!(this.p.codigo || this.p.nbs || this.p.descricao); },
    aplicarPadrao() {
        this.nbs = this.p.nbs;
        this.descricao = this.p.descricao;
        this.$dispatch('definir-servico', this.p.codigo);
    },
    init() {
        this.$watch('emitente', () => { if (!this.temPadrao) this.usarPadrao = false; else if (this.usarPadrao) this.aplicarPadrao(); });
        this.$watch('usarPadrao', (v) => { if (v) this.aplicarPadrao(); });
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

Alpine.start();
