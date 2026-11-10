<x-app-layout titulo="Clientes">
    @php $f = fn ($n) => number_format($n, 0, ',', '.'); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Clientes</h1>
                <p class="mt-1 text-[15px] text-ink-700">Quem recebe as suas notas. Cadastre vários de uma vez por planilha, ou deixe o cadastro ser atualizado quando você importa notas com a coluna “Atualizar” marcada.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2" x-data>
                <a href="{{ route('tomadores.modelo') }}" class="btn" title="Baixar a planilha modelo (.xlsx) para cadastrar clientes">Baixar planilha modelo</a>
                @can('emitir')
                    <a href="{{ route('tomadores.criar') }}" class="btn btn-primary" title="Cadastrar um cliente preenchendo a tela">Novo cliente</a>
                    <form method="POST" action="{{ route('tomadores.importar') }}" enctype="multipart/form-data" x-ref="form">
                        @csrf
                        <input type="file" name="planilha" accept=".xlsx,.xls,.csv" class="sr-only" x-ref="arquivo" @change="if ($refs.arquivo.files.length) $refs.form.submit()">
                        <button type="button" class="btn" @click="$refs.arquivo.click()" title="Escolha a planilha preenchida; a importação começa na hora">Importar clientes</button>
                    </form>
                @endcan
            </div>
        </div>

        @error('planilha')<div role="alert" class="rounded-xl bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900">{{ $message }}</div>@enderror
        @if ($r = session('importacao_clientes'))
            <div role="status" class="card p-4 sm:p-5 space-y-2 border-2 border-brand-600">
                <div class="font-semibold">Planilha {{ $r['arquivo'] }} importada</div>
                <div class="text-sm text-ink-700 flex flex-wrap gap-x-5 gap-y-1">
                    <span><b class="font-mono text-ink-900">{{ $r['criados'] }}</b> {{ $r['criados'] === 1 ? 'cliente novo' : 'clientes novos' }}</span>
                    <span><b class="font-mono text-ink-900">{{ $r['atualizados'] }}</b> {{ $r['atualizados'] === 1 ? 'atualizado' : 'atualizados' }}</span>
                    <span><b class="font-mono text-ink-900">{{ $r['sem_mudanca'] }}</b> sem mudança</span>
                    <span><b class="font-mono {{ count($r['erros']) ? 'text-warn-800' : 'text-ink-900' }}">{{ count($r['erros']) }}</b> {{ count($r['erros']) === 1 ? 'linha com problema' : 'linhas com problema' }}</span>
                </div>
                @if ($r['erros'])
                    <ul class="text-sm text-warn-900 bg-warn-50 rounded-lg p-3 space-y-0.5 max-h-48 overflow-auto">
                        @foreach ($r['erros'] as $e)<li>Linha {{ $e['linha'] }}: {{ $e['mensagem'] }}</li>@endforeach
                    </ul>
                @endif
            </div>
        @endif

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
                    <tr><th class="px-5 py-3 font-medium"><x-ordenavel col="cliente" :ordem="$ordem" :dir="$dir">Cliente</x-ordenavel></th><th class="px-3 py-3 font-medium"><x-ordenavel col="cidade" :ordem="$ordem" :dir="$dir">Cidade</x-ordenavel></th><th class="px-3 py-3 font-medium"><x-ordenavel col="emails" :ordem="$ordem" :dir="$dir">E-mails</x-ordenavel></th><th class="px-5 py-3 font-medium"><x-ordenavel col="situacao" :ordem="$ordem" :dir="$dir">Situação</x-ordenavel></th><th class="px-5 py-3 font-medium text-right">Ações</th></tr>
                </thead>
                <tbody data-lista="clientes">
                @forelse ($tomadores as $t)
                    <tr class="tabela-linha hover:bg-gray-50" data-item="{{ $t->id }}">
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
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('tomadores.editar', $t) }}" class="btn-acao !px-0 w-[34px]" title="{{ Gate::allows('emitir') ? 'Editar este cliente' : 'Ver este cliente' }}" aria-label="{{ Gate::allows('emitir') ? 'Editar' : 'Ver' }} {{ $t->nome }}">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
                                </a>
                                @can('emitir')
                                    @if ($t->notas_emitidas > 0)
                                        <span class="inline-flex items-center justify-center w-[34px] h-[34px] rounded-lg text-ink-600 opacity-40 cursor-not-allowed" tabindex="0" role="img"
                                              title="Não dá para excluir: este cliente tem {{ $t->notas_emitidas }} {{ $t->notas_emitidas === 1 ? 'nota emitida' : 'notas emitidas' }}." aria-label="Exclusão indisponível: cliente com notas emitidas">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>
                                        </span>
                                    @else
                                        <button type="button" class="btn-excluir !px-2" data-acao-url="{{ route('tomadores.excluir', $t) }}" data-confirmar="Excluir mesmo?" data-saindo="self" title="Excluir este cliente" aria-label="Excluir {{ $t->nome }}">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>
                                        </button>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr class="tabela-linha"><td colspan="5" class="px-5 py-10 text-center text-ink-700">Nenhum cliente ainda. Cadastre em “Novo cliente” ou deixe que apareçam depois da primeira importação confirmada.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $tomadores->links() }}
    </div>
</x-app-layout>
