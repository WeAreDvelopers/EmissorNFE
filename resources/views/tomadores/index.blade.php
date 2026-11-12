<x-app-layout titulo="Tomadores (Clientes)">
    @php $f = fn ($n) => number_format($n, 0, ',', '.'); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Tomadores (Clientes)</h1>
                <p class="mt-1 text-[15px] text-ink-700">Quem recebe as notas de <b>{{ app(\App\Support\Tenancy\EmitenteAtual::class)->emitente()?->razao_social ?? 'sua empresa' }}</b>. Cadastre vários de uma vez por planilha, ou deixe o cadastro ser atualizado quando você importa notas com a coluna “Atualizar” marcada.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2" x-data>
                <a href="{{ route('tomadores.modelo') }}" class="btn" title="Baixar a planilha modelo (.xlsx) para cadastrar tomadores">Baixar planilha modelo</a>
                @can('emitir')
                    <a href="{{ route('tomadores.importar.tela') }}" class="btn" title="Enviar a planilha preenchida para cadastrar ou atualizar tomadores">Importar tomadores</a>
                    <a href="{{ route('tomadores.criar') }}" class="btn btn-primary" title="Cadastrar um tomador preenchendo a tela">+ Novo tomador</a>
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

        <form method="GET" class="card p-4 sm:p-5 flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label for="q" class="campo-rotulo">Buscar</label>
                <input id="q" name="q" value="{{ $busca }}" placeholder="Nome, CNPJ/CPF ou e-mail" class="campo-entrada" autocomplete="off">
            </div>
            <div class="w-[190px] shrink-0">
                <label for="tipo" class="campo-rotulo">Tipo</label>
                <select id="tipo" name="tipo" class="campo-entrada">
                    <option value="">Todos (PF e PJ)</option>
                    <option value="pf" @selected($tipo === 'pf')>Pessoa física (PF)</option>
                    <option value="pj" @selected($tipo === 'pj')>Pessoa jurídica (PJ)</option>
                </select>
            </div>
            <label class="flex items-center gap-2 min-h-[44px] text-sm whitespace-nowrap"><input type="checkbox" name="pendentes" value="1" @checked($soPendentes) class="rounded text-brand-600 focus:ring-brand-600"> Só com cadastro incompleto ({{ $f($totalPendentes) }})</label>
            <div class="flex items-center gap-3 ms-auto">
                @if (filled($busca) || $soPendentes || $tipo !== '')<a href="{{ route('tomadores.index') }}" class="text-sm text-ink-700 hover:underline">Limpar filtros</a>@endif
                <button class="btn btn-primary">Filtrar</button>
            </div>
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
                            <div class="flex items-start gap-2">
                                <span class="pill shrink-0 {{ $t->tipoPessoa() === 'PF' ? 'bg-info-100 text-info-800' : 'bg-gray-100 text-ink-700' }}" title="{{ $t->tipoPessoa() === 'PF' ? 'Pessoa física (CPF)' : 'Pessoa jurídica (CNPJ)' }}">{{ $t->tipoPessoa() }}</span>
                                <a href="{{ route('tomadores.editar', $t) }}" class="font-medium text-ink-900 no-underline hover:underline">{{ $t->nome }}</a>
                            </div>
                            <div class="text-xs text-ink-600"><span class="font-mono">{{ \App\Support\Documento::formatar($t->documento) }}</span>@if ($t->nome_fantasia) · {{ $t->nome_fantasia }}@endif</div>
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">{{ $t->municipio ? $t->municipio.' / '.$t->uf : '—' }}</td>
                        <td class="px-3 py-3 text-ink-700">{{ count($t->emails ?? []) ? \Illuminate\Support\Str::limit(implode(', ', $t->emails), 60) : '—' }}</td>
                        <td class="px-5 py-3">
                            @if ($t->pendente)<span class="pill bg-amber-100 text-amber-900" title="Faltam: {{ implode(', ', $t->faltando()) }}">pendente de correção</span>
                            @else<span class="pill bg-ok-100 text-ok-800">completo</span>@endif
                            @unless ($t->ativo)<span class="pill bg-gray-200 text-ink-700">inativo</span>@endunless
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('tomadores.editar', $t) }}" class="btn-acao !px-0 w-[34px]" title="{{ Gate::allows('emitir') ? 'Editar este cliente' : 'Ver este cliente' }}" aria-label="{{ Gate::allows('emitir') ? 'Editar' : 'Ver' }} {{ $t->nome }}">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
                                </a>
                                @can('emitir')
                                    <form method="POST" action="{{ route('tomadores.situacao', $t) }}" class="inline-flex">@csrf
                                        <button type="submit" role="switch" aria-checked="{{ $t->ativo ? 'true' : 'false' }}" title="{{ $t->ativo ? 'Cadastro ativo: clique para inativar' : 'Cadastro inativo: clique para ativar' }}" aria-label="{{ $t->ativo ? 'Inativar' : 'Ativar' }} {{ $t->nome }}"
                                                class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $t->ativo ? 'bg-brand-600' : 'bg-gray-400' }}">
                                            <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform {{ $t->ativo ? 'translate-x-6' : 'translate-x-1' }}"></span>
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr class="tabela-linha"><td colspan="5" class="px-5 py-10 text-center text-ink-700">Nenhum tomador ainda. Cadastre em “+ Novo tomador” ou deixe que apareçam depois da primeira importação confirmada.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $tomadores->links() }}
    </div>
</x-app-layout>
