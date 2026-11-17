<x-app-layout titulo="Master · Erros da prefeitura">
    <div class="max-w-[1240px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-3xl font-semibold">Erros da prefeitura</h1>
                <span class="pill bg-info-100 text-info-800">Master · somente leitura</span>
            </div>
            <p class="mt-1 text-[15px] text-ink-700">Códigos que já apareceram nas recusas, do mais frequente ao menos. Os marcados "sem tradução" não estão no catálogo: inclua uma linha em <span class="font-mono">resources/data/erros-nfse.php</span>.</p>
        </div>

        <form method="GET" class="flex items-center gap-3">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="sem_traducao" value="1" @checked($so) onchange="this.form.submit()" class="rounded text-brand-600 focus:ring-brand-600"> Só os sem tradução</label>
        </form>

        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-ink-600 bg-chao/60 border-b border-linha">
                    <tr>
                        <th class="px-5 py-3 font-medium">Código</th>
                        <th class="px-3 py-3 font-medium text-right">Vezes</th>
                        <th class="px-3 py-3 font-medium">Tradução</th>
                        <th class="px-3 py-3 font-medium">Ação</th>
                        <th class="px-3 py-3 font-medium">Último texto</th>
                        <th class="px-5 py-3 font-medium">Última vez</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($erros as $e)
                    <tr class="tabela-linha align-top">
                        <td class="px-5 py-3 font-mono font-semibold">{{ $e->codigo }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($e->ocorrencias, 0, ',', '.') }}</td>
                        <td class="px-3 py-3">
                            @if (! $e->entrada)<span class="pill bg-warn-100 text-warn-900">Sem tradução</span>
                            @elseif ($e->entrada['texto_proprio'])<span class="pill bg-ok-100 text-ok-800">Texto próprio</span>
                            @else<span class="pill bg-gray-100 text-ink-700">Só orientação base</span>@endif
                        </td>
                        <td class="px-3 py-3">{{ $e->entrada['acao'] ?? '—' }}</td>
                        <td class="px-3 py-3 text-xs text-ink-700 max-w-[480px] break-words">{{ \Illuminate\Support\Str::limit($e->ultimo_texto, 220) }}</td>
                        <td class="px-5 py-3 text-ink-700">{{ $e->ultima_vez ? \Illuminate\Support\Carbon::parse($e->ultima_vez)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-ink-700">Nenhum erro registrado ainda.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
