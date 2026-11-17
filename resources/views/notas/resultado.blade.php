<x-app-layout titulo="Emissão da nota">
    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-2">
        <h1 class="text-3xl font-semibold">Emissão da nota</h1>
        <p class="text-[15px] text-ink-700">Acompanhando o envio para a prefeitura…</p>
    </div>

    {{-- Pop-up com o resultado. Texto provisório: "Nota Emitida !!" ou "Nota Rejeitada !! Ver erro e corrigir." --}}
    <div x-data="{
            e: @js($estado), url: @js(route('notas.situacao', $nota)), tentativas: 0,
            async iniciar() {
                while (!this.e.fim && this.tentativas < 45) {
                    await new Promise((r) => setTimeout(r, 2000));
                    try { const r = await fetch(this.url, { headers: { Accept: 'application/json' } }); if (r.ok) this.e = await r.json(); } catch (x) { /* tenta de novo */ }
                    this.tentativas++;
                }
                if (!this.e.fim) this.e = { fim: true, resultado: 'espera', titulo: 'Ainda processando', texto: 'A prefeitura ainda não respondeu. O resultado aparece em Notas.', corrigir: null };
            }
         }" x-init="iniciar()"
         class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50" role="alertdialog" aria-modal="true" aria-live="polite" aria-labelledby="resultado-titulo">
        <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl p-6 space-y-4">
            <div class="flex items-start gap-3">
                <span class="w-10 h-10 shrink-0 rounded-full flex items-center justify-center"
                      :class="{ 'bg-ok-100 text-ok-800': e.resultado === 'ok', 'bg-perigo-50 text-perigo-700': e.resultado === 'erro', 'bg-warn-100 text-warn-800': e.resultado === 'espera', 'bg-gray-100 text-ink-700': e.resultado === 'enviando' }" aria-hidden="true">
                    <svg x-show="e.resultado === 'enviando'" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 3a9 9 0 1 0 9 9"/></svg>
                    <svg x-show="e.resultado === 'ok'" x-cloak class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                    <svg x-show="e.resultado === 'erro' || e.resultado === 'espera'" x-cloak class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 17h.01"/></svg>
                </span>
                <div class="min-w-0 space-y-2">
                    <h2 id="resultado-titulo" class="text-lg font-semibold text-ink-900" x-text="e.titulo"></h2>
                    <p class="text-[15px] text-ink-800" x-text="e.texto"></p>
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2" x-show="e.fim" x-cloak>
                <a x-show="e.corrigir" :href="e.corrigir" class="btn btn-primary" x-init="$el.focus()">Ver erro e corrigir</a>
                <a href="{{ route('notas.index') }}" class="btn" :class="e.corrigir ? '' : 'btn-primary'">Ir para Notas</a>
            </div>
        </div>
    </div>
</x-app-layout>
