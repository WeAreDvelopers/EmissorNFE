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

Alpine.start();
