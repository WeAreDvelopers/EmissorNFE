<x-app-layout titulo="Conferir importação de tomadores">
    @php
        $fmt = fn ($n) => number_format($n, 0, ',', '.');
        $acoes = ['novo' => ['novo', 'bg-info-100 text-info-800'], 'atualizar' => ['atualizar', 'bg-brand-100 text-ok-800'], 'sem_mudanca' => ['sem mudança', 'bg-gray-100 text-ink-700']];
        $colunasTabela = [
            'documento' => 'CPF/CNPJ', 'nome' => 'Razão Social/Nome', 'nome_fantasia' => 'Nome Fantasia', 'logradouro' => 'Logradouro', 'numero' => 'Número',
            'complemento' => 'Complemento', 'bairro' => 'Bairro', 'municipio' => 'Município', 'uf' => 'UF', 'cep' => 'CEP', 'emails' => 'E-mails', 'observacoes' => 'Observações',
        ];
        $importaveis = $validas + $comErro;
    @endphp

    <div class="max-w-[1240px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-3">
            <a href="{{ route('tomadores.index') }}" class="link text-sm">← Tomadores (Clientes)</a>
            <h1 class="text-3xl font-semibold">Importar tomadores</h1>
            <x-passos :atual="2" :rotulos="['Escolher arquivo', 'Conferir', 'Importar']" />
            <p class="text-[15px] text-ink-700"><b>Nada foi gravado ainda.</b> Confira a prévia abaixo e escolha o que importar.</p>
        </div>

        @error('planilha')<div role="alert" class="rounded-xl bg-perigo-600 text-white px-5 py-4 text-[15px]">{{ $message }}</div>@enderror
        @error('modo')<div role="alert" class="rounded-xl bg-perigo-600 text-white px-5 py-4 text-[15px]">{{ $message }}</div>@enderror

        <div class="flex flex-wrap gap-6 items-start">
            {{-- Resumo da prévia --}}
            <section class="card p-5 sm:p-6 space-y-4 flex-[999_1_560px] min-w-0">
                <div class="flex flex-wrap items-center gap-4">
                    <span class="w-11 h-11 rounded-lg bg-brand-100 text-ok-800 flex items-center justify-center font-mono text-xs font-medium" aria-hidden="true">{{ strtoupper(pathinfo($arquivo, PATHINFO_EXTENSION)) }}</span>
                    <div class="min-w-0">
                        <div class="font-medium truncate">{{ $arquivo }}</div>
                        <div class="text-[13px] text-ink-600">{{ $fmt($total) }} {{ $total === 1 ? 'linha lida' : 'linhas lidas' }} · tomadores de {{ $empresa?->razao_social }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="rounded-xl bg-ok-50 border border-brand-200 p-4"><div class="font-mono text-3xl font-semibold text-ok-800">{{ $fmt($validas) }}</div><div class="text-sm font-medium mt-0.5">{{ $validas === 1 ? 'linha correta' : 'linhas corretas' }}</div></div>
                    <div class="rounded-xl {{ $comErro ? 'bg-perigo-50 border-perigo-200' : 'bg-chao border-linha' }} border p-4"><div class="font-mono text-3xl font-semibold {{ $comErro ? 'text-perigo-700' : 'text-ink-600' }}">{{ $fmt($comErro) }}</div><div class="text-sm font-medium mt-0.5">com erro em algum campo</div></div>
                    <div class="rounded-xl {{ $bloqueadas ? 'bg-perigo-50 border-perigo-200' : 'bg-chao border-linha' }} border p-4"><div class="font-mono text-3xl font-semibold {{ $bloqueadas ? 'text-perigo-700' : 'text-ink-600' }}">{{ $fmt($bloqueadas) }}</div><div class="text-sm font-medium mt-0.5">não podem ser importadas</div></div>
                    <div class="rounded-xl bg-chao border border-linha p-4"><div class="font-mono text-3xl font-semibold">{{ $fmt($novos) }}<span class="text-ink-600 text-lg"> + {{ $fmt($atualizar) }}</span></div><div class="text-sm font-medium mt-0.5">novos + atualizações</div></div>
                </div>
                @if ($comErro || $bloqueadas)
                    <p class="text-[13px] text-ink-700 leading-snug">
                        <b>Com erro em algum campo:</b> o cadastro ainda pode ser criado, mas sem o campo errado, e fica <i>pendente de correção</i>.
                        <b>Não podem ser importadas:</b> CNPJ/CPF inválido ou repetido, ou cliente novo sem nome. Essas linhas ficam de fora em qualquer opção.
                    </p>
                @endif
            </section>

            {{-- Escolha --}}
            <aside class="flex-[1_1_320px] min-w-0">
                <form method="POST" action="{{ route('tomadores.importar.confirmar', $token) }}" class="card p-5 space-y-4" data-carregando="Importando…">
                    @csrf
                    <h2 class="text-base font-semibold">O que importar?</h2>
                    <fieldset class="space-y-2.5">
                        <label class="flex gap-2.5 items-start border-[1.5px] border-brand-600 bg-brand-50 rounded-lg p-3 text-sm leading-snug cursor-pointer">
                            <input type="radio" name="modo" value="validos" checked class="mt-1 text-brand-600 focus:ring-brand-600">
                            <span><b>Só as {{ $fmt($validas) }} corretas</b> <span class="text-xs font-semibold text-ok-800">recomendado</span><br><span class="text-ink-600">As {{ $fmt($total - $validas) }} com erro ficam de fora para você corrigir e importar depois.</span></span>
                        </label>
                        <label class="flex gap-2.5 items-start border border-gray-300 rounded-lg p-3 text-sm leading-snug cursor-pointer @if (! $comErro) opacity-60 @endif">
                            <input type="radio" name="modo" value="todos" class="mt-1 text-brand-600 focus:ring-brand-600" @disabled(! $comErro)>
                            <span><b>Corretas e com erro ({{ $fmt($importaveis) }})</b><br><span class="text-ink-600">{{ $comErro ? 'Os campos errados ficam em branco e esses cadastros ficam pendentes de correção.' : 'Nenhuma linha com erro que possa entrar.' }}</span></span>
                        </label>
                    </fieldset>
                    <button class="btn btn-primary btn-lg w-full" @disabled(! $importaveis)>Importar →</button>
                </form>
                <form method="POST" action="{{ route('tomadores.importar.cancelar', $token) }}" class="mt-3 text-center">@csrf @method('DELETE')
                    <a href="{{ route('tomadores.importar.tela') }}" class="text-sm text-ink-700 hover:underline">Trocar arquivo</a> ·
                    <button class="text-sm text-ink-700 hover:underline">Cancelar</button>
                </form>
            </aside>
        </div>

        {{-- Prévia completa: todos os dados da planilha, com o que está errado em vermelho --}}
        <section class="card pt-5 overflow-hidden">
            <div class="px-5 sm:px-6 pb-3">
                <h2 class="text-lg font-semibold">Todas as linhas da planilha</h2>
                <p class="text-sm text-ink-600 mt-1">O que está <b class="text-perigo-700">em vermelho</b> precisa de correção: passe o mouse (ou use a tecla Tab) para ver o motivo. Para corrigir, ajuste a planilha e envie de novo.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1500px] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-ink-600 bg-chao/60 border-y border-linha">
                        <tr>
                            <th class="ps-5 sm:ps-6 pe-3 py-3 font-medium w-[60px]">Linha</th>
                            <th class="pe-3 py-3 font-medium w-[130px]">Situação</th>
                            @foreach ($colunasTabela as $campo => $rotulo)<th class="pe-3 py-3 font-medium whitespace-nowrap">{{ $rotulo }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($linhas as $l)
                            @php $erros = $l['erros']; @endphp
                            <tr @class(['tabela-linha align-top', 'bg-perigo-50/40' => $erros])>
                                <td class="ps-5 sm:ps-6 pe-3 py-3 font-mono text-ink-600">{{ $l['linha'] }}</td>
                                <td class="pe-3 py-3 whitespace-nowrap">
                                    @if ($l['bloqueada'])<span class="pill bg-perigo-50 text-perigo-700">não importa</span>
                                    @elseif ($erros)<span class="pill bg-perigo-50 text-perigo-700">com erro</span>
                                    @else<span class="pill {{ $acoes[$l['acao']][1] }}">{{ $acoes[$l['acao']][0] }}</span>@endif
                                </td>
                                @foreach ($colunasTabela as $campo => $rotulo)
                                    @php $valor = $l['bruto'][$campo] ?? ''; @endphp
                                    <td class="pe-3 py-3 leading-snug {{ in_array($campo, ['documento', 'uf', 'cep', 'numero'], true) ? 'font-mono text-[13px] whitespace-nowrap' : ($campo === 'emails' ? 'break-all' : '') }}">
                                        <x-celula-erro :msg="$erros[$campo] ?? null">{{ $valor !== '' ? ($campo === 'documento' && $l['documento'] ? \App\Support\Documento::formatar($l['documento']) : $valor) : '—' }}</x-celula-erro>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
