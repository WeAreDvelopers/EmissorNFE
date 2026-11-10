import './bootstrap';
import './ajax';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Campo de código de serviço no estilo select2: parece um select ("01.07.01 - Descrição"), e ao clicar
// abre uma lista com busca na lista nacional (cadastro da empresa, nota manual e correção de nota)
Alpine.data('buscaServico', (inicial, url) => ({
    texto: inicial || '',      // código escolhido: é o que vai no formulário
    descricao: '',
    termo: '',
    itens: [],
    aberto: false,
    indice: 0,
    ctl: null,
    fmt(c) { return /^\d{6}$/.test(c) ? `${c.slice(0, 2)}.${c.slice(2, 4)}.${c.slice(4)}` : c; },
    init() { if (/^\d{6}$/.test(this.texto)) this.carregarDescricao(); },
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
    definir(codigo) { this.texto = codigo || ''; this.descricao = ''; if (/^\d{6}$/.test(this.texto)) this.carregarDescricao(); },
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
