<x-app-layout titulo="Pagamento via Pix">
    @php
        $svg = null;
        if ($pagamento->pix_copia_cola) {
            $svg = (new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(240, 1),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            )))->writeString($pagamento->pix_copia_cola);
        }
    @endphp

    <div class="max-w-xl mx-auto px-4 sm:px-6 py-8"
         x-data="{ status: '{{ $pagamento->status->value }}', copiado: false,
                   async checar() { const r = await fetch('{{ route('pagamentos.status', $pagamento) }}', { headers: { Accept: 'application/json' } }); if (r.ok) { this.status = (await r.json()).status; } } }"
         x-init="if (status === 'pendente') { const t = setInterval(async () => { await checar(); if (status !== 'pendente') clearInterval(t); }, 4000); }">

        <div class="card p-6 text-center space-y-5">
            <div>
                <div class="text-sm text-ink-600">Pagamento via Pix</div>
                <div class="text-2xl font-semibold">R$ {{ number_format($pagamento->valor_centavos / 100, 2, ',', '.') }}</div>
                <div class="text-sm text-ink-700">{{ number_format($pagamento->creditos, 0, ',', '.') }} créditos</div>
            </div>

            <template x-if="status === 'pendente'">
                <div class="space-y-4">
                    <div class="inline-block bg-white p-2 border border-gray-300 rounded-lg">{!! $svg !!}</div>
                    <div class="text-left space-y-2">
                        <label for="cc" class="campo-rotulo">Ou use o Pix copia e cola</label>
                        <div class="flex gap-2">
                            <input id="cc" readonly x-ref="codigo" value="{{ $pagamento->pix_copia_cola }}" class="flex-1 min-w-0 min-h-[44px] rounded-lg border-gray-400 font-mono text-xs">
                            <button type="button" class="btn btn-primary" @click="navigator.clipboard.writeText($refs.codigo.value); copiado = true; setTimeout(() => copiado = false, 2000)"><span x-show="!copiado">Copiar</span><span x-show="copiado" x-cloak>Copiado!</span></button>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 rounded-lg bg-info-100 px-3.5 py-3 text-sm text-info-800 text-left" role="status">
                        <span class="w-2.5 h-2.5 rounded-full bg-info-800 shrink-0 animate-pulse" aria-hidden="true"></span>
                        <span>Aguardando pagamento. Esta tela atualiza sozinha.@if ($pagamento->expira_em) Vence às <b class="font-mono">{{ $pagamento->expira_em->format('H:i') }}</b>.@endif</span>
                    </div>
                    <p class="text-[13px] text-ink-600 leading-relaxed">Pagou e fechou a página? Sem problema: o crédito entra quando o banco confirmar.</p>
                </div>
            </template>

            <template x-if="status === 'pago'">
                <div class="text-ok-800 space-y-2" role="status">
                    <p class="font-semibold text-lg">Pagamento confirmado!</p>
                    <p class="text-[15px]">Os créditos já estão na sua conta. Notas que aguardavam crédito voltam para a fila sozinhas.</p>
                    <a href="{{ route('creditos.index') }}" class="btn btn-primary mt-2">Ver saldo</a>
                </div>
            </template>

            <template x-if="status === 'expirado' || status === 'falhou' || status === 'devolvido'">
                <div class="text-warn-800 space-y-2" role="alert">
                    <p class="font-semibold">Este Pix não está mais válido.</p>
                    <a href="{{ route('creditos.index') }}" class="btn btn-primary mt-2">Gerar outro</a>
                </div>
            </template>

            @if (config('saas.gateway') === 'fake' && $pagamento->status->value === 'pendente')
                <form method="POST" action="{{ route('dev.pix.pagar', $pagamento) }}" class="border-t pt-4">
                    @csrf
                    <button class="text-xs text-amber-900 underline">[desenvolvimento] Simular pagamento</button>
                </form>
            @endif

            <a href="{{ route('creditos.index') }}" class="block text-sm text-ink-700">Escolher outro pacote</a>
        </div>
    </div>
</x-app-layout>
