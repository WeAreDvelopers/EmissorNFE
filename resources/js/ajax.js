/**
 * Camada AJAX do sistema: formulários e botões enviam sem recarregar a página, com retorno visual
 * (barra de progresso, botão com "carregando", aviso na tela, linha que some com animação) e a tela
 * se atualiza trocando só os pedaços que mudaram (data-regiao / data-lista), sem perder a rolagem.
 *
 *   form[data-ajax]            envia por fetch; 422 mostra o erro embaixo do campo
 *   form[data-carregando]      envio normal, mas o botão mostra "carregando" (upload, enviar lote…)
 *   [data-acao-url]            botão que chama a URL (DELETE por padrão), com data-confirmar em 2 cliques
 *   data-saindo="self"         ao dar certo, o elemento [data-item] some com animação
 *   data-regiao="nome"         pedaço da página que é trocado pelo da versão nova
 *   data-lista="nome"          lista de [data-item]: entram os novos, saem os que sumiram (não mexe nos que estão sendo digitados)
 *   data-poll                  a tela se atualiza sozinha a cada poucos segundos enquanto houver trabalho em andamento
 */

const csrf = () => document.querySelector('meta[name=csrf-token]')?.content ?? '';
const reduzMovimento = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/* ---------- barra de progresso no topo ---------- */
let emVoo = 0;
function barra(delta) {
    emVoo = Math.max(0, emVoo + delta);
    let el = document.getElementById('barra-ajax');
    if (!el) {
        el = document.createElement('div');
        el.id = 'barra-ajax';
        el.className = 'barra-ajax';
        el.setAttribute('role', 'progressbar');
        el.setAttribute('aria-label', 'Processando');
        document.body.appendChild(el);
    }
    el.classList.toggle('ativa', emVoo > 0);
}

/* ---------- aviso (toast) ---------- */
export function aviso(mensagem, tipo = 'ok') {
    let caixa = document.getElementById('avisos-ajax');
    if (!caixa) {
        caixa = document.createElement('div');
        caixa.id = 'avisos-ajax';
        caixa.className = 'avisos-ajax';
        caixa.setAttribute('aria-live', 'polite');
        document.body.appendChild(caixa);
    }
    const el = document.createElement('div');
    el.className = `aviso-ajax aviso-${tipo}`;
    el.setAttribute('role', tipo === 'erro' ? 'alert' : 'status');
    el.innerHTML = `<span class="aviso-icone" aria-hidden="true">${tipo === 'erro' ? '!' : '✓'}</span><span class="aviso-texto"></span>`;
    el.querySelector('.aviso-texto').textContent = mensagem;
    caixa.appendChild(el);
    requestAnimationFrame(() => el.classList.add('entrou'));
    setTimeout(() => { el.classList.remove('entrou'); setTimeout(() => el.remove(), 300); }, tipo === 'erro' ? 6500 : 3800);
}

/* ---------- botão em "carregando" ---------- */
function carregando(botao, texto) {
    if (!botao || botao.dataset.carregandoAtivo) return () => {};
    botao.dataset.carregandoAtivo = '1';
    const original = botao.innerHTML;
    botao.disabled = true;
    botao.setAttribute('aria-busy', 'true');
    botao.innerHTML = `<span class="girando" aria-hidden="true"></span><span>${texto || 'Salvando…'}</span>`;

    return () => {
        botao.disabled = false;
        botao.removeAttribute('aria-busy');
        delete botao.dataset.carregandoAtivo;
        botao.innerHTML = original;
    };
}

/* ---------- animações ---------- */
export function sair(el) {
    return new Promise((resolve) => {
        if (!el || !el.isConnected) return resolve();
        el.classList.add('saindo');
        if (reduzMovimento()) { el.remove(); return resolve(); }

        const tabela = ['TBODY', 'TR'].includes(el.tagName);
        el.style.transition = 'opacity .28s ease, max-height .35s ease, padding .35s ease, margin .35s ease';
        if (!tabela) {
            el.style.maxHeight = el.scrollHeight + 'px';
            el.style.overflow = 'hidden';
            void el.offsetHeight;
            el.style.maxHeight = '0px';
            el.style.paddingTop = el.style.paddingBottom = '0px';
            el.style.marginTop = el.style.marginBottom = '0px';
        }
        el.style.opacity = '0';
        setTimeout(() => { el.remove(); resolve(); }, 360);
    });
}

function entrar(el) {
    if (reduzMovimento()) return;
    el.classList.add('entrando');
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.remove('entrando')));
}

function destacar(el) {
    if (!el || reduzMovimento()) return;
    el.classList.add('piscou');
    setTimeout(() => el.classList.remove('piscou'), 1200);
}

/* ---------- troca de pedaços da página ---------- */
function digitando(el) {
    const ativo = document.activeElement;
    return !!ativo && el.contains(ativo) && /^(INPUT|TEXTAREA|SELECT)$/.test(ativo.tagName);
}

export async function atualizar() {
    let resposta;
    barra(1);
    try {
        resposta = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
    } catch { barra(-1); return false; }
    barra(-1);
    if (!resposta.ok) return false;

    const nova = new DOMParser().parseFromString(await resposta.text(), 'text/html');

    // listas: entram os novos itens, saem os que sumiram; o que está na tela fica como está
    document.querySelectorAll('[data-lista]').forEach((atual) => {
        const nome = atual.dataset.lista;
        const novaLista = nova.querySelector(`[data-lista="${CSS.escape(nome)}"]`);
        if (!novaLista) { sair(atual); return; }

        const idsNovos = new Set([...novaLista.querySelectorAll('[data-item]')].map((i) => i.dataset.item));
        atual.querySelectorAll('[data-item]').forEach((i) => { if (!idsNovos.has(i.dataset.item) && !i.classList.contains('saindo')) sair(i); });

        const idsAtuais = new Set([...atual.querySelectorAll('[data-item]')].map((i) => i.dataset.item));
        const ancora = atual.querySelector('[data-lista-fim]');
        novaLista.querySelectorAll('[data-item]').forEach((i) => {
            if (idsAtuais.has(i.dataset.item)) return;
            const no = document.importNode(i, true);
            ancora ? ancora.before(no) : atual.appendChild(no);
            entrar(no);
        });
    });

    // regiões: trocadas por inteiro (menos se a pessoa está digitando dentro delas)
    document.querySelectorAll('[data-regiao]').forEach((atual) => {
        const nome = atual.dataset.regiao;
        const nova_ = nova.querySelector(`[data-regiao="${CSS.escape(nome)}"]`);
        if (!nova_) { if (nome.startsWith('linha-')) sair(atual); return; }
        if (digitando(atual)) return;
        if (atual.dataset.versao ? atual.dataset.versao === nova_.dataset.versao : atual.outerHTML === nova_.outerHTML) return;
        const no = document.importNode(nova_, true);
        atual.replaceWith(no);
        destacar(no);
    });

    return nova;
}

/* ---------- envio de formulário ---------- */
function limparErros(form) {
    form.querySelectorAll('.erro-campo').forEach((e) => e.remove());
    form.querySelectorAll('.campo-invalido').forEach((e) => e.classList.remove('campo-invalido'));
}

function mostrarErros(form, erros) {
    let primeiro = null;
    Object.entries(erros || {}).forEach(([campo, mensagens]) => {
        const nome = campo.replace(/\.(\w+)/g, '[$1]');
        const entrada = form.querySelector(`[name="${CSS.escape(nome)}"]`) || form.querySelector(`[name="${CSS.escape(campo)}"]`);
        const texto = Array.isArray(mensagens) ? mensagens[0] : String(mensagens);
        if (!entrada) { aviso(texto, 'erro'); return; }
        entrada.classList.add('campo-invalido');
        const p = document.createElement('p');
        p.className = 'erro-campo';
        p.setAttribute('role', 'alert');
        p.textContent = texto;
        (entrada.closest('label') ?? entrada.parentElement).appendChild(p);
        primeiro ??= entrada;
    });
    if (primeiro) { primeiro.focus({ preventScroll: true }); primeiro.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    const caixa = form.closest('[data-item]') ?? form;
    if (!reduzMovimento()) { caixa.classList.add('tremer'); setTimeout(() => caixa.classList.remove('tremer'), 450); }
}

async function chamar(url, opcoes) {
    barra(1);
    try {
        const resposta = await fetch(url, { credentials: 'same-origin', ...opcoes, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf(), ...(opcoes.headers || {}) } });
        let json = null;
        try { json = await resposta.json(); } catch { /* resposta sem corpo */ }
        return { ok: resposta.ok, status: resposta.status, json };
    } catch {
        return { ok: false, status: 0, json: null };
    } finally { barra(-1); }
}

function entradaDe(item) {
    return item?.querySelector('input:not([type=hidden]):not([type=checkbox]), textarea') ?? null;
}

/** Foca o item que passou a ocupar o lugar do que saiu. Só rola se ele estiver fora da tela (e sem pular). */
function focarNoLugarDe(lista, indice) {
    if (!lista?.isConnected) return;
    const restantes = [...lista.querySelectorAll('[data-item]:not(.saindo)')];
    const alvo = entradaDe(restantes[Math.min(indice, restantes.length - 1)]);
    if (!alvo) return;
    alvo.focus({ preventScroll: true });
    const r = alvo.getBoundingClientRect();
    if (r.top < 0 || r.bottom > innerHeight) alvo.scrollIntoView({ block: 'center', behavior: reduzMovimento() ? 'auto' : 'smooth' });
}

async function aposSucesso(origem, json) {
    if (origem.dataset.redireciona) {
        if (json?.mensagem) sessionStorage.setItem('aviso-ajax', json.mensagem);
        location.href = origem.dataset.redireciona;
        return;
    }
    if (json?.mensagem) aviso(json.mensagem, 'ok');

    const item = origem.closest('[data-item]');
    const alvoSaida = origem.dataset.saindo === 'self' ? item : null;
    const lista = alvoSaida?.closest('[data-lista]');
    const indice = lista ? [...lista.querySelectorAll('[data-item]')].indexOf(alvoSaida) : 0;

    if (alvoSaida) await sair(alvoSaida);
    if (origem.dataset.fecharPainel !== undefined) fecharPainel(false);

    await atualizar();
    // o que sumiu na atualização ainda está animando: espera terminar para escolher o foco certo
    if (lista) setTimeout(() => focarNoLugarDe(lista, indice), 380);
}

document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (form.matches('form[data-carregando]')) {
        const botao = e.submitter ?? form.querySelector('button:not([type=button])');
        // deixa o envio normal seguir; só troca o botão depois que o navegador começou a enviar
        setTimeout(() => carregando(botao, form.dataset.carregando), 0);
        return;
    }
    if (!form.matches('form[data-ajax]')) return;

    e.preventDefault();
    limparErros(form);

    const botao = e.submitter ?? form.querySelector('button:not([type=button])');
    const restaurar = carregando(botao, form.dataset.ajaxTexto || 'Salvando…');
    const { ok, status, json } = await chamar(form.action, { method: 'POST', body: new FormData(form) });
    restaurar();

    if (ok) { await aposSucesso(form, json); return; }
    if (status === 422 && json?.errors) { mostrarErros(form, json.errors); return; }
    aviso(json?.message || 'Não foi possível concluir agora. Tente de novo.', 'erro');
});

/* ---------- botões de ação (excluir…) com confirmação em dois cliques ---------- */
const armados = new WeakMap();

document.addEventListener('click', async (e) => {
    const botao = e.target.closest('[data-acao-url]');
    if (!botao) return;
    e.preventDefault();

    if (botao.dataset.confirmar && !armados.has(botao)) {
        const original = botao.innerHTML;
        botao.innerHTML = `<span>${botao.dataset.confirmar}</span>`;
        botao.classList.add('confirmando');
        const t = setTimeout(() => { botao.innerHTML = original; botao.classList.remove('confirmando'); armados.delete(botao); }, 4000);
        armados.set(botao, { original, t });
        return;
    }
    const estado = armados.get(botao);
    if (estado) { clearTimeout(estado.t); armados.delete(botao); botao.classList.remove('confirmando'); botao.innerHTML = estado.original; }

    const restaurar = carregando(botao, botao.dataset.ajaxTexto || 'Excluindo…');
    const { ok, json } = await chamar(botao.dataset.acaoUrl, { method: botao.dataset.metodo || 'DELETE' });
    restaurar();

    if (ok) await aposSucesso(botao, json);
    else aviso(json?.message || 'Não foi possível concluir agora. Tente de novo.', 'erro');
});

/* ---------- painel lateral (abrir/fechar sem recarregar) ---------- */
function fecharPainel(historico = true, url) {
    const vaga = document.getElementById('painel-vaga');
    if (vaga) vaga.innerHTML = '';
    if (historico && url) history.pushState({}, '', url);
    else { const u = new URL(location.href); u.searchParams.delete('nota'); history.replaceState({}, '', u); }
    atualizar();
}

document.addEventListener('click', async (e) => {
    const fechar = e.target.closest('[data-fechar]');
    if (fechar) { e.preventDefault(); fecharPainel(true, fechar.getAttribute('href')); return; }

    const abrir = e.target.closest('a[data-painel]');
    if (!abrir) return;
    e.preventDefault();

    barra(1);
    try {
        const r = await fetch(abrir.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
        if (!r.ok) { location.href = abrir.href; return; }
        const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
        const novo = doc.getElementById('painel-vaga');
        const vaga = document.getElementById('painel-vaga');
        if (!novo || !vaga) { location.href = abrir.href; return; }
        vaga.innerHTML = novo.innerHTML;
        history.pushState({}, '', abrir.href);
        atualizar(); // a lista marca a linha escolhida
        if (window.matchMedia('(max-width: 1023px)').matches) vaga.scrollIntoView({ behavior: 'smooth', block: 'start' });
        vaga.querySelector('input:not([type=hidden]):not([type=checkbox]), textarea')?.focus({ preventScroll: true });
    } catch { location.href = abrir.href; } finally { barra(-1); }
});

window.addEventListener('popstate', () => location.reload());

/* ---------- a tela se atualiza sozinha enquanto há trabalho em andamento ---------- */
function iniciarPoll() {
    if (!document.querySelector('[data-poll]')) return;
    const t = setInterval(async () => {
        if (document.hidden) return;
        const nova = await atualizar();
        if (nova && !nova.querySelector('[data-poll]')) clearInterval(t);
    }, 4000);
}
document.addEventListener('DOMContentLoaded', () => {
    iniciarPoll();
    const guardado = sessionStorage.getItem('aviso-ajax');
    if (guardado) { sessionStorage.removeItem('aviso-ajax'); aviso(guardado, 'ok'); }
});

window.Ajax = { aviso, atualizar, sair };
