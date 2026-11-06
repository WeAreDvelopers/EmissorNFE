<x-app-layout titulo="Clientes">
    @php $f = fn ($n) => number_format($n, 0, ',', '.'); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div>
            <h1 class="text-3xl font-semibold">Clientes</h1>
            <p class="mt-1 text-[15px] text-ink-700">Quem recebe as suas notas. O cadastro é atualizado quando você importa uma planilha com a coluna “Atualizar” marcada e confirma o envio.</p>
        </div>

        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[220px]">
                <label for="q" class="campo-rotulo">Buscar</label>
                <input id="q" name="q" value="{{ $busca }}" placeholder="Nome, CNPJ/CPF ou e-mail" class="campo-entrada" autocomplete="off">
            </div>
            <label class="flex items-center gap-2 min-h-[44px] text-sm"><input type="checkbox" name="pendentes" value="1" @checked($soPendentes) class="rounded text-brand-600 focus:ring-brand-600"> Só com cadastro incompleto ({{ $f($totalPendentes) }})</label>
            <button class="btn btn-primary">Filtrar</button>
        </form>

        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-ink-600 bg-chao/60 border-b border-linha">
                    <tr><th class="px-5 py-3 font-medium">Cliente</th><th class="px-3 py-3 font-medium">Cidade</th><th class="px-3 py-3 font-medium">E-mails</th><th class="px-5 py-3 font-medium">Situação</th></tr>
                </thead>
                <tbody>
                @forelse ($tomadores as $t)
                    <tr class="tabela-linha hover:bg-gray-50">
                        <td class="px-5 py-3">
                            <a href="{{ route('tomadores.editar', $t) }}" class="font-medium text-ink-900 no-underline hover:underline">{{ $t->nome }}</a>
                            <div class="text-xs text-ink-600"><span class="font-mono">{{ \App\Support\Documento::formatar($t->documento) }}</span>@if ($t->nome_fantasia) · {{ $t->nome_fantasia }}@endif</div>
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">{{ $t->municipio ? $t->municipio.' / '.$t->uf : '—' }}</td>
                        <td class="px-3 py-3 text-ink-700">{{ count($t->emails ?? []) ? \Illuminate\Support\Str::limit(implode(', ', $t->emails), 60) : '—' }}</td>
                        <td class="px-5 py-3">
                            @if ($t->pendente)<span class="pill bg-amber-100 text-amber-900" title="Faltam: {{ implode(', ', $t->faltando()) }}">pendente de correção</span>
                            @else<span class="pill bg-ok-100 text-ok-800">completo</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr class="tabela-linha"><td colspan="4" class="px-5 py-10 text-center text-ink-700">Nenhum cliente ainda. Eles aparecem aqui depois da primeira importação confirmada.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $tomadores->links() }}
    </div>
</x-app-layout>
