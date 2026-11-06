<x-app-layout titulo="Cancelar NFS-e">
    <div class="max-w-[760px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-2">
            <a href="{{ route('notas.index', ['q' => $nota->numero_nfse]) }}" class="link text-sm">← Notas</a>
            <h1 class="text-3xl font-semibold">Cancelar NFS-e nº {{ $nota->numero_nfse }}</h1>
        </div>

        <div class="card p-5 space-y-1 text-sm">
            <div class="font-medium">{{ $nota->tomador_rotulo }}</div>
            <div class="text-ink-600">R$ {{ number_format($nota->valor, 2, ',', '.') }} · competência {{ $nota->data_competencia?->format('d/m/Y') }} · {{ $nota->emitente?->razao_social }}</div>
            <div class="font-mono text-xs text-ink-600 break-all">Chave {{ $nota->chave_acesso }}</div>
        </div>

        <div class="rounded-xl bg-warn-50 border border-warn-200 px-5 py-4 text-sm text-warn-900 leading-relaxed" role="note">
            <b>O cancelamento não tem volta.</b> A NFS-e deixa de valer na prefeitura e o cliente pode ser avisado. O crédito usado nesta nota não é devolvido. Se o valor ou um dado estava errado, depois do cancelamento você emite uma nota nova.
            @if ($nota->emitente?->ehHomologacao())<div class="mt-1.5">Esta empresa está em modo teste: o cancelamento vale só no ambiente de testes.</div>@endif
        </div>

        <form method="POST" action="{{ route('notas.cancelar', $nota) }}" class="card p-5 sm:p-6 space-y-4">
            @csrf
            @error('cancelamento')<div class="rounded-lg bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900" role="alert">{{ $message }}</div>@enderror

            <div>
                <label for="motivo_codigo" class="campo-rotulo">Motivo</label>
                <select id="motivo_codigo" name="motivo_codigo" class="campo-entrada" required>
                    <option value="">Escolha…</option>
                    @foreach ($motivos as $cod => $rotulo)<option value="{{ $cod }}" @selected(old('motivo_codigo') == $cod)>{{ $rotulo }}</option>@endforeach
                </select>
                @error('motivo_codigo')<p class="erro-campo">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="motivo_texto" class="campo-rotulo">Explique o que aconteceu</label>
                <textarea id="motivo_texto" name="motivo_texto" rows="3" maxlength="255" class="campo-entrada" required placeholder="Ex.: Valor digitado errado na planilha, será emitida nova nota.">{{ old('motivo_texto') }}</textarea>
                <p class="campo-ajuda">De 15 a 255 caracteres. Vai junto com o pedido à prefeitura.</p>
                @error('motivo_texto')<p class="erro-campo">{{ $message }}</p>@enderror
            </div>
            <label class="flex items-start gap-2.5 text-sm cursor-pointer">
                <input type="checkbox" name="confirmo" value="1" class="mt-0.5 rounded text-perigo-600 focus:ring-perigo-600" @checked(old('confirmo'))>
                <span>Entendo que o cancelamento não pode ser desfeito.</span>
            </label>
            @error('confirmo')<p class="erro-campo">{{ $message }}</p>@enderror

            <div class="flex items-center gap-4 pt-1">
                <button class="btn btn-lg bg-perigo-600 border-perigo-600 text-white hover:bg-perigo-700">Cancelar esta NFS-e</button>
                <a href="{{ route('notas.index', ['q' => $nota->numero_nfse]) }}" class="text-sm text-ink-700">Voltar sem cancelar</a>
            </div>
        </form>
    </div>
</x-app-layout>
