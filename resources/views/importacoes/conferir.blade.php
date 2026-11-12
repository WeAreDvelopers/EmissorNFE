<x-app-layout titulo="Conferir importação">
    @php
        $lendo = in_array($importacao->status, ['pendente', 'lendo'], true);
        $falhou = $importacao->status === 'falhou';
        $emTeste = $importacao->emitente->ambiente == 2;
        $fmt = fn ($n) => number_format($n, 0, ',', '.');
    @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-3">
            <a href="{{ route('importacoes.index') }}" class="link text-sm">← Importações</a>
            <h1 class="text-3xl font-semibold">Nova importação</h1>
            <x-passos :atual="$lendo || $falhou ? 1 : 2" />
        </div>

        <div class="card p-4 sm:px-5 flex flex-wrap items-center gap-4" data-regiao="arquivo" data-versao="{{ $total }}-{{ $importacao->status }}">
            @if ($importacao->emitente->logo_url)
                <span class="w-12 h-12 shrink-0 rounded-lg border border-gray-200 bg-white flex items-center justify-center overflow-hidden"><img src="{{ $importacao->emitente->logo_url }}" alt="Logo de {{ $importacao->emitente->razao_social }}" class="max-w-full max-h-full object-contain"></span>
            @endif
            <span class="w-11 h-11 rounded-lg bg-brand-100 text-ok-800 flex items-center justify-center font-mono text-xs font-medium" aria-hidden="true">{{ strtoupper(pathinfo($importacao->arquivo_nome, PATHINFO_EXTENSION)) }}</span>
            <div class="flex-1 min-w-[200px]">
                <div class="font-medium">{{ $importacao->arquivo_nome }}</div>
                <div class="text-[13px] text-ink-600">
                    @if ($lendo) lendo as linhas…
                    @elseif ($falhou) não foi possível ler
                    @else {{ $fmt($total) }} linhas lidas · {{ $importacao->emitente->razao_social }}
                    @endif
                </div>
            </div>
            @can('emitir')<a href="{{ route('importacoes.editar', $importacao) }}" class="btn btn-sm">Editar em tabela</a>@endcan
            @unless ($importacao->reprocessada_de_id)<a href="{{ route('importacoes.criar') }}" class="btn btn-sm">Trocar arquivo</a>@endunless
            @can('emitir')
                <button type="button" class="btn-excluir" data-acao-url="{{ route('importacoes.excluir', $importacao) }}" data-confirmar="Clique de novo para excluir" data-ajax-texto="Excluindo…" data-redireciona="{{ route('importacoes.index') }}">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>Excluir importação
                </button>
            @endcan
        </div>

        @if ($falhou)
            <div class="card p-6 border-warn-200 bg-warn-50">
                <h2 class="text-lg font-semibold text-warn-900">Não conseguimos ler esta planilha</h2>
                <p class="mt-1 text-[15px] text-warn-800">{{ $importacao->erro }}</p>
                <div class="mt-4 flex gap-3 flex-wrap">
                    <a href="{{ route('importacoes.modelo') }}" class="btn btn-sm">Baixar planilha modelo</a>
                    <a href="{{ route('importacoes.criar') }}" class="btn btn-primary btn-sm">Enviar outro arquivo</a>
                </div>
            </div>

        @elseif ($lendo)
            <div class="card p-10 text-center"
                 x-data x-init="const t = setInterval(async () => { const r = await fetch('{{ route('importacoes.progresso', $importacao) }}', { headers: { Accept: 'application/json' } }); if (r.ok) { const d = await r.json(); if (!['pendente','lendo'].includes(d.status)) { clearInterval(t); location.reload(); } } }, 2000)">
                <div class="mx-auto w-10 h-10 rounded-full border-4 border-brand-100 border-t-brand-600 animate-spin" role="status" aria-label="Lendo a planilha"></div>
                <h2 class="mt-4 text-lg font-semibold">Lendo e conferindo a sua planilha…</h2>
                <p class="mt-1 text-[15px] text-ink-700">Isso leva alguns segundos. Nada é emitido nem cobrado nesta etapa.</p>
            </div>

        @else
        <div class="flex flex-wrap gap-6 items-start">

            <div class="flex-[999_1_620px] min-w-0 space-y-5">
                @foreach ($importacao->avisos ?? [] as $aviso)
                    <div class="rounded-xl bg-amber-50 border border-amber-300 px-4 py-3.5 text-sm text-amber-900 flex gap-3" role="alert">
                        <svg class="w-5 h-5 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                        <span><b>Confira antes de enviar.</b> {{ $aviso }}</span>
                    </div>
                @endforeach

                @if ($duplicidades->isNotEmpty())
                    <section class="card p-5 sm:p-6 border-2 border-amber-400" id="duplicidades" aria-labelledby="titulo-dup">
                        <h2 id="titulo-dup" class="text-lg font-semibold">{{ $duplicidades->count() }} {{ $duplicidades->count() === 1 ? 'linha parece' : 'linhas parecem' }} repetir uma nota que já existe</h2>
                        <p class="text-sm text-ink-700 mt-1">Mesma empresa, mesmo cliente, mesmo valor e mesma data. A planilha não traz um número único da cobrança, então <b>você decide</b>: se for outra cobrança, mantenha; se for repetida, exclua. Só dá para enviar depois de decidir todas.</p>
                        <div class="mt-3 divide-y divide-gray-200">
                            @foreach ($duplicidades as $d)
                                @php $o = $d->duplicadaDe; @endphp
                                <div class="py-3 text-sm flex flex-wrap items-center justify-between gap-3" id="dup-{{ $d->id }}">
                                    <div class="min-w-0">
                                        <div class="font-medium">Linha {{ $d->linha_planilha }} · {{ $d->tomador_rotulo }} · R$ {{ number_format($d->valor, 2, ',', '.') }} · {{ $d->data_competencia?->format('d/m/Y') }}</div>
                                        <div class="text-[13px] text-ink-600">
                                            @if ($o && $o->importacao_id === $importacao->id) Igual à linha {{ $o->linha_planilha }} deste mesmo arquivo.
                                            @elseif ($o) Já existe: {{ $o->status->label() }}@if ($o->numero_nfse) (NFS-e nº {{ $o->numero_nfse }})@endif @if ($o->importacao) no arquivo “{{ $o->importacao->arquivo_nome }}”@endif.
                                            @endif
                                        </div>
                                    </div>
                                    @can('emitir')
                                        <div class="flex gap-2 shrink-0">
                                            <form method="POST" action="{{ route('notas.duplicidade.aprovar', $d) }}">@csrf<button class="btn btn-sm">É outra cobrança, manter</button></form>
                                            <form method="POST" action="{{ route('notas.excluir', $d) }}">@csrf @method('DELETE')<button class="btn-excluir">Excluir linha</button></form>
                                        </div>
                                    @endcan
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @php $atualizacoes = collect($importacao->atualizacoes ?? []); $rotulosCampos = \App\Models\Tomador::CAMPOS; $fmtCampo = fn ($v) => is_array($v) ? implode(', ', $v) : ($v === null || $v === '' ? '—' : $v); @endphp
                @if ($atualizacoes->isNotEmpty())
                    <details class="card p-5 sm:p-6" open>
                        <summary class="cursor-pointer text-lg font-semibold">Cadastro de clientes: {{ $atualizacoes->where('novo', true)->count() }} {{ $atualizacoes->where('novo', true)->count() === 1 ? 'novo' : 'novos' }} e {{ $atualizacoes->where('novo', false)->count() }} {{ $atualizacoes->where('novo', false)->count() === 1 ? 'atualizado' : 'atualizados' }}</summary>
                        <p class="text-sm text-ink-700 mt-2">Nada foi gravado ainda. Quando você enviar as notas, o cadastro é atualizado assim e a mudança fica no histórico do cliente.</p>
                        <div class="mt-3 divide-y divide-gray-200">
                            @foreach ($atualizacoes as $a)
                                <div class="py-3 text-sm">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium">{{ $a['nome'] ?: 'Sem nome' }}</span>
                                        <span class="font-mono text-xs text-ink-600">{{ \App\Support\Documento::formatar($a['documento']) }}</span>
                                        <span class="pill {{ $a['novo'] ? 'bg-info-100 text-info-800' : 'bg-gray-100 text-ink-700' }}">{{ $a['novo'] ? 'cliente novo' : 'atualizar' }}</span>
                                    </div>
                                    @foreach ($a['campos'] as $campo => [$de, $para])
                                        @if (isset($rotulosCampos[$campo]) && ! ($a['novo'] && $campo === 'nome'))
                                            <div class="text-[13px] leading-snug mt-0.5"><b>{{ $rotulosCampos[$campo] }}:</b> @unless ($a['novo'])<span class="text-ink-600">{{ $fmtCampo($de) }}</span> → @endunless{{ $fmtCampo($para) }}</div>
                                        @endif
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif

                <div class="card p-5 sm:p-6 space-y-4" data-regiao="resumo" data-versao="{{ $prontas }}-{{ $qtdInvalidas }}-{{ $somaValores }}-{{ $saldo }}">
                    <h2 class="text-xl font-semibold">
                        @if ($prontas && $qtdInvalidas) Encontramos {{ $fmt($prontas) }} {{ $prontas === 1 ? 'nota pronta' : 'notas prontas' }} e {{ $fmt($qtdInvalidas) }} com problema
                        @elseif ($prontas) Tudo certo: {{ $fmt($prontas) }} {{ $prontas === 1 ? 'nota pronta' : 'notas prontas' }} para emitir
                        @else Nenhuma nota pronta ainda: {{ $fmt($qtdInvalidas) }} com problema
                        @endif
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div class="rounded-xl bg-ok-50 border border-brand-200 p-4"><div class="font-mono text-3xl font-semibold text-ok-800">{{ $fmt($prontas) }}</div><div class="text-sm font-medium text-ok-800">prontas para emitir</div></div>
                        <div class="rounded-xl {{ $qtdInvalidas ? 'bg-warn-50 border-warn-200' : 'bg-chao border-linha' }} border p-4"><div class="font-mono text-3xl font-semibold {{ $qtdInvalidas ? 'text-warn-800' : '' }}">{{ $fmt($qtdInvalidas) }}</div><div class="text-sm font-medium {{ $qtdInvalidas ? 'text-warn-800' : 'text-ink-700' }}">precisam de correção</div></div>
                        <div class="rounded-xl bg-chao border border-linha p-4"><div class="font-mono text-2xl font-semibold leading-9">R$ {{ number_format($somaValores, 2, ',', '.') }}</div><div class="text-sm font-medium text-ink-700">soma das notas prontas</div></div>
                    </div>

                    @cobranca
                    @if ($semCusto)
                        <div class="rounded-lg bg-ok-50 border border-brand-200 px-4 py-3.5 text-sm leading-relaxed text-ok-800">
                            @if ($cobrancaDesligada)
                                <b>Esta empresa não paga por nota.</b> O envio não consome créditos do seu saldo.
                            @else
                                <b>Modo teste: não gasta créditos.</b> As notas enviadas em homologação não consomem o seu saldo e não têm valor fiscal.
                            @endif
                        </div>
                    @else
                    <div class="rounded-lg bg-chao px-4 py-3.5 text-sm leading-relaxed text-ink-900">
                        <b>Custo:</b> {{ $fmt($prontas) }} {{ $prontas === 1 ? 'crédito será reservado' : 'créditos serão reservados' }}.
                        Você tem <b class="font-mono">{{ $fmt($saldo) }}</b>@unless ($semSaldo) e fica com <b class="font-mono">{{ $fmt($saldoDepois) }}</b>@endunless.
                        Nota recusada ou que falhar devolve o crédito.
                    </div>
                    @endif
                    @if ($semSaldo && $prontas)
                        <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3.5 text-sm text-amber-900">
                            <b>Saldo insuficiente para tudo.</b> Se você enviar agora, as primeiras {{ $fmt(max($saldo, 0)) }} saem e as outras {{ $fmt($prontas - max($saldo, 0)) }} ficam <b>aguardando crédito</b>, voltando sozinhas depois da recarga.
                            @can('comprar-creditos')<a href="{{ route('creditos.index') }}" class="link ms-1">Recarregar antes</a>@endcan
                        </div>
                    @endif
                    @endcobranca

                    <div class="border-t border-gray-200 pt-4">
                        <h3 class="text-sm font-semibold mb-2">Depois de enviar</h3>
                        <ul class="grid sm:grid-cols-3 gap-x-6 gap-y-2 text-sm text-ink-700">
                            <li class="flex gap-2"><span class="text-brand-600" aria-hidden="true">●</span>As notas saem em segundo plano, cerca de {{ config('nfse.limite_por_minuto', 60) }} por minuto.</li>
                            <li class="flex gap-2"><span class="text-brand-600" aria-hidden="true">●</span>Você pode fechar a página.</li>
                            <li class="flex gap-2"><span class="text-brand-600" aria-hidden="true">●</span>O que precisar de você aparece no Início.</li>
                        </ul>
                    </div>
                </div>

                @if ($qtdInvalidas)
                <div class="card pt-5 pb-2" data-lista="corrija">
                    <div class="px-5 sm:px-6 pb-3">
                        <h2 class="text-lg font-semibold">Corrija agora, sem enviar a planilha de novo</h2>
                        <p class="text-sm text-ink-600 mt-1">Cada linha mostra o que está errado. Edite o campo e aperte <kbd class="font-mono text-xs border border-gray-300 rounded px-1">Enter</kbd> ou <b>Confirmar</b>: a linha some daqui e a próxima já fica pronta para digitar.</p>
                    </div>
                    @foreach ($invalidas as $n)
                        @php
                            $ex = $n->explicacao;
                            $umCampo = count($ex['campos']) === 1 ? $ex['campos'][0] : null;
                            $valorDe = fn ($campo) => match ($campo) {
                                'data_competencia' => $n->data_competencia?->format('d/m/Y'),
                                'valor' => $n->valor > 0 ? number_format($n->valor, 2, ',', '') : '',
                                default => $n->{$campo},
                            };
                            // Outras linhas com o mesmo valor errado no mesmo campo: dá para corrigir todas de uma vez
                            $mesmoProblema = $umCampo && in_array($umCampo, ['codigo_servico', 'data_competencia', 'tomador_email', 'codigo_nbs'], true)
                                ? \App\Models\NotaFiscal::where('importacao_id', $importacao->id)->where('status', \App\Enums\StatusNota::Invalida)->where($umCampo, $n->getRawOriginal($umCampo))->whereKeyNot($n->id)->count()
                                : 0;
                        @endphp
                        <form method="POST" action="{{ route('notas.corrigir', $n) }}" data-ajax data-ajax-texto="Salvando…" data-saindo="self" data-item="{{ $n->id }}" id="corrija-{{ $n->id }}"
                              class="px-5 sm:px-6 py-4 tabela-linha grid grid-cols-1 md:grid-cols-[64px_minmax(0,1.1fr)_minmax(0,1.6fr)_auto] gap-3 md:gap-4 md:items-end">
                            @csrf @method('PATCH')
                            <span class="font-mono text-[13px] text-ink-600 md:pb-3">linha {{ $n->linha_planilha }}</span>
                            <div class="md:pb-1.5">
                                <div class="font-medium text-sm">{{ $n->tomador_nome ?: '(sem nome)' }}</div>
                                <div class="text-[13px] text-warn-800">{{ $ex['o_que_aconteceu'] }}</div>
                            </div>
                            <div class="grid gap-2.5">
                                @foreach ($ex['campos'] as $campo)
                                    <label class="text-xs text-ink-600">{{ \App\Support\ExplicacaoErro::ROTULOS[$campo] }}
                                        <input name="{{ $campo }}" value="{{ $valorDe($campo) }}" autocomplete="off" class="mt-1 block w-full min-h-[40px] rounded-lg border-gray-400 text-sm font-mono focus:border-brand-600 focus:ring-brand-600">
                                    </label>
                                @endforeach
                                @if ($mesmoProblema)
                                    <input type="hidden" name="campo_aplicar" value="{{ $umCampo }}">
                                    <label class="flex items-center gap-2 text-[13px] text-ink-700"><input type="checkbox" name="aplicar_em_todas" value="1" class="rounded text-brand-600 focus:ring-brand-600"> Aplicar também nas outras {{ $mesmoProblema }} {{ $mesmoProblema === 1 ? 'linha' : 'linhas' }} com este mesmo valor</label>
                                @endif
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button class="btn btn-sm btn-primary">Confirmar</button>
                                @can('emitir')
                                    <button type="button" class="btn-excluir" data-acao-url="{{ route('notas.excluir', $n) }}" data-confirmar="Excluir mesmo?" data-saindo="self" title="Tirar esta linha do lote" aria-label="Excluir a linha {{ $n->linha_planilha }}">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>Excluir
                                    </button>
                                @endcan
                            </div>
                        </form>
                    @endforeach
                    <div data-lista-fim data-regiao="corrija-mais" data-versao="{{ $qtdInvalidas }}">
                        @if ($qtdInvalidas > $invalidas->count())
                            <div class="px-5 sm:px-6 py-3.5 tabela-linha text-sm text-ink-700">Mais {{ $fmt($qtdInvalidas - $invalidas->count()) }} linhas com problema. Corrija estas e a lista se renova.</div>
                        @endif
                    </div>
                </div>
                @endif

            </div>

            <aside class="flex-[1_1_300px] min-w-0 space-y-4">
                <form method="POST" action="{{ route('importacoes.enviar', $importacao) }}" class="card p-5 space-y-4" data-regiao="envio" data-versao="{{ $prontas }}-{{ $qtdInvalidas }}-{{ $saldo }}" data-carregando="Enviando…">
                    @csrf
                    <h2 class="text-base font-semibold">Resumo do envio</h2>
                    <div class="text-sm flex items-center gap-3">
                        @if ($importacao->emitente->logo_url)<span class="w-12 h-12 shrink-0 rounded-lg border border-gray-200 bg-white flex items-center justify-center overflow-hidden"><img src="{{ $importacao->emitente->logo_url }}" alt="" class="max-w-full max-h-full object-contain"></span>@endif
                        <div><div class="text-xs text-ink-600">Empresa que emite</div><div class="font-medium">{{ $importacao->emitente->razao_social }}</div></div>
                    </div>
                    @if ($emTeste)
                        <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5 text-[13px] leading-snug text-amber-900"><b>Modo teste.</b> As notas não têm valor fiscal.</div>
                    @endif

                    @if ($qtdInvalidas && $prontas)
                        <fieldset class="space-y-2.5">
                            <legend class="text-sm font-semibold mb-2">O que enviar?</legend>
                            <label class="flex gap-2.5 items-start border-[1.5px] border-brand-600 bg-brand-50 rounded-lg p-3 text-sm leading-snug cursor-pointer">
                                <input type="radio" name="modo" value="enviar_prontas" checked class="mt-1 text-brand-600 focus:ring-brand-600">
                                <span><b>Só as {{ $fmt($prontas) }} prontas</b> <span class="text-xs font-semibold text-ok-800">recomendado</span><br><span class="text-ink-600">As {{ $fmt($qtdInvalidas) }} com problema ficam esperando você corrigir.</span></span>
                            </label>
                            <label class="flex gap-2.5 items-start border border-gray-300 rounded-lg p-3 text-sm leading-snug cursor-pointer">
                                <input type="radio" name="modo" value="corrigir_antes" class="mt-1 text-brand-600 focus:ring-brand-600">
                                <span><b>Corrigir tudo antes</b><br><span class="text-ink-600">Não enviamos nada agora.</span></span>
                            </label>
                        </fieldset>
                    @endif

                    @can('emitir')
                        @if ($duplicidades->isNotEmpty())<p class="text-[13px] text-amber-900 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5" role="alert">Decida as {{ $duplicidades->count() }} possíveis duplicidades antes de enviar.</p>@endif
                        @error('duplicidade')<p class="text-[13px] text-warn-800" role="alert">{{ $message }}</p>@enderror
                        <button class="btn btn-primary btn-lg w-full" @disabled(! $prontas || $duplicidades->isNotEmpty())>Enviar {{ $fmt($prontas) }} {{ $prontas === 1 ? 'nota' : 'notas' }} →</button>
                    @endcan
                    <a href="{{ route('importacoes.index') }}" class="block text-center text-sm text-ink-700">Cancelar</a>
                </form>
            </aside>
        </div>

        {{-- Listagem em largura total (fora das duas colunas) para conferir sem rolar para o lado --}}
        <section class="card pt-5 overflow-hidden" id="linhas">
            <div class="px-5 sm:px-6 pb-3 space-y-3">
                <div>
                    <h2 class="text-lg font-semibold">Todas as linhas da planilha</h2>
                    <p class="text-sm text-ink-600 mt-1">Todos os dados lidos da planilha. O que está <b class="text-perigo-700">em vermelho</b> precisa de correção: passe o mouse (ou use a tecla Tab) para ver o motivo. Use <b>Editar</b> para ajustar qualquer linha antes de enviar.</p>
                </div>
                {{-- Prévia: quantas linhas estão corretas e quais precisam de correção --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border {{ $qtdInvalidas ? 'border-perigo-200 bg-perigo-50' : 'border-brand-200 bg-ok-50' }} px-4 py-3 text-sm">
                    <span class="inline-flex items-center gap-2"><b class="font-mono text-xl text-ok-800">{{ $fmt($prontas) }}</b> <span class="text-ink-900">{{ $prontas === 1 ? 'linha correta' : 'linhas corretas' }}</span></span>
                    <span class="inline-flex items-center gap-2"><b class="font-mono text-xl {{ $qtdInvalidas ? 'text-perigo-700' : 'text-ink-600' }}">{{ $fmt($qtdInvalidas) }}</b> <span class="text-ink-900">{{ $qtdInvalidas === 1 ? 'precisa de correção' : 'precisam de correção' }}</span></span>
                    @if ($linhasComProblema->isNotEmpty())
                        <span class="text-ink-700">Linhas:
                            @foreach ($linhasComProblema as $lp)<a href="{{ route('importacoes.conferir', [$importacao, 'ver' => 'problemas']) }}#linha-{{ $lp->id }}" class="font-mono font-semibold text-perigo-700 hover:underline">{{ $lp->linha_planilha }}</a>@unless ($loop->last), @endunless @endforeach
                            @if ($qtdInvalidas > $linhasComProblema->count()) e mais {{ $fmt($qtdInvalidas - $linhasComProblema->count()) }} @endif
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2" data-regiao="filtros" data-versao="{{ $total }}-{{ $prontas }}-{{ $qtdInvalidas }}-{{ $ver }}">
                    @foreach (['todas' => ['Todas', $total], 'prontas' => ['Prontas', $prontas], 'problemas' => ['Com problema', $qtdInvalidas]] as $chave => [$rotulo, $qtd])
                        <a href="{{ route('importacoes.conferir', [$importacao, 'ver' => $chave]) }}#linhas" @class(['inline-flex items-center gap-1.5 min-h-[40px] px-3.5 rounded-full border text-sm no-underline', 'bg-ink-900 text-white border-ink-900' => $ver === $chave, 'bg-white text-ink-900 border-gray-300 hover:bg-gray-50' => $ver !== $chave])>{{ $rotulo }} <span class="opacity-80">{{ $fmt($qtd) }}</span></a>
                    @endforeach
                </div>
            </div>

            {{-- overflow-x-auto só como rede de segurança em telas pequenas; em tela larga a tabela cabe inteira --}}
            {{-- overflow-x-auto: a tabela mostra todos os dados da planilha e rola para o lado em tela estreita --}}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1320px] text-sm">
                    <colgroup>
                        <col class="w-[60px]"><col class="w-[120px]"><col class="w-[150px]"><col class="w-[18%]"><col class="w-[15%]"><col class="w-[96px]"><col class="w-[104px]"><col><col class="w-[112px]"><col class="w-[108px]"><col class="w-[150px]">
                    </colgroup>
                    <thead class="text-left text-xs uppercase tracking-wide text-ink-600 bg-chao/60 border-y border-linha">
                        <tr>
                            <th class="ps-5 sm:ps-6 pe-3 py-3 font-medium"><x-ordenavel col="linha" :ordem="$ordem" :dir="$dir">Linha</x-ordenavel></th>
                            <th class="pe-3 py-3 font-medium"><x-ordenavel col="situacao" :ordem="$ordem" :dir="$dir">Situação</x-ordenavel></th>
                            <th class="pe-3 py-3 font-medium">CPF/CNPJ</th>
                            <th class="pe-3 py-3 font-medium"><x-ordenavel col="cliente" :ordem="$ordem" :dir="$dir">Cliente</x-ordenavel></th>
                            <th class="pe-3 py-3 font-medium">E-mail</th>
                            <th class="pe-3 py-3 font-medium"><x-ordenavel col="servico" :ordem="$ordem" :dir="$dir">Serviço</x-ordenavel></th>
                            <th class="pe-3 py-3 font-medium">NBS</th>
                            <th class="pe-3 py-3 font-medium"><x-ordenavel col="descricao" :ordem="$ordem" :dir="$dir">Descrição</x-ordenavel></th>
                            <th class="pe-5 py-3 font-medium text-right"><x-ordenavel col="valor" :ordem="$ordem" :dir="$dir" class="justify-self-end">Valor</x-ordenavel></th>
                            <th class="pe-3 py-3 font-medium"><x-ordenavel col="competencia" :ordem="$ordem" :dir="$dir">Competência</x-ordenavel></th>
                            <th class="pe-5 sm:pe-6 py-3 font-medium"><span class="sr-only">Ação</span></th>
                        </tr>
                    </thead>
                    @forelse ($linhas as $l)
                        @php
                            $problema = $l->status === \App\Enums\StatusNota::Invalida;
                            $comErro = (int) old('_nota') === $l->id;   // a edição falhou na validação: reabre com o que a pessoa digitou
                            $v = fn ($campo, $atual) => $comErro ? old($campo) : $atual;
                            $entrada = 'mt-1 block w-full min-h-[40px] rounded-lg border-gray-400 text-sm focus:border-brand-600 focus:ring-brand-600';
                            $e = $errosCampo[$l->id] ?? [];
                            $cel = fn ($campo) => $e[$campo] ?? null;
                        @endphp
                        <tbody x-data="{ editando: {{ $comErro ? 'true' : 'false' }} }" id="linha-{{ $l->id }}" data-regiao="linha-{{ $l->id }}" data-versao="{{ $l->updated_at?->timestamp }}-{{ $l->status->value }}" data-item="t{{ $l->id }}">
                            <tr @class(['tabela-linha align-top', 'bg-perigo-50/40' => $problema, 'hover:bg-gray-50' => ! $problema]) x-show="!editando">
                                <td class="ps-5 sm:ps-6 pe-3 py-3.5 font-mono text-ink-600">{{ $l->linha_planilha }}</td>
                                <td class="pe-3 py-3.5 whitespace-nowrap">
                                    @if ($problema)<x-celula-erro :msg="$cel('_linha')" titulo="Motivo"><span class="pill bg-perigo-50 text-perigo-700">Com problema</span></x-celula-erro>
                                    @else<span class="pill bg-ok-100 text-ok-800">Pronta</span>@endif
                                </td>
                                <td class="pe-3 py-3.5 font-mono text-[13px] whitespace-nowrap">
                                    @if ($l->sem_tomador)<span class="text-ink-600">sem destinatário</span>
                                    @else<x-celula-erro :msg="$cel('tomador_documento')">{{ $l->tomador_documento ? \App\Support\Documento::formatar($l->tomador_documento) : '—' }}</x-celula-erro>@endif
                                </td>
                                <td class="pe-4 py-3.5 leading-snug"><x-celula-erro :msg="$cel('tomador_nome')"><span class="font-medium">{{ $l->tomador_nome ?: ($l->sem_tomador ? '—' : '(sem nome)') }}</span></x-celula-erro></td>
                                <td class="pe-4 py-3.5 leading-snug break-all"><x-celula-erro :msg="$cel('tomador_email')">{{ $l->tomador_email ?: '—' }}</x-celula-erro></td>
                                <td class="pe-3 py-3.5 whitespace-nowrap font-mono text-[13px]"><x-celula-erro :msg="$cel('codigo_servico')">{{ $l->codigo_servico ?: '—' }}</x-celula-erro></td>
                                <td class="pe-3 py-3.5 whitespace-nowrap font-mono text-[13px]"><x-celula-erro :msg="$cel('codigo_nbs')">{{ $l->codigo_nbs ?: '—' }}</x-celula-erro></td>
                                <td class="pe-4 py-3.5 text-ink-900 leading-snug"><x-celula-erro :msg="$cel('descricao')">{{ $l->descricao ?: '—' }}</x-celula-erro></td>
                                <td class="pe-5 py-3.5 font-mono text-right whitespace-nowrap"><x-celula-erro :msg="$cel('valor')">{{ $l->valor > 0 ? 'R$ '.number_format($l->valor, 2, ',', '.') : '—' }}</x-celula-erro></td>
                                <td class="pe-3 py-3.5 whitespace-nowrap text-ink-700"><x-celula-erro :msg="$cel('data_competencia')">{{ $l->data_competencia?->format('d/m/Y') ?: '—' }}</x-celula-erro></td>
                                <td class="pe-4 sm:pe-5 py-2 text-right whitespace-nowrap">
                                    @can('emitir')<button type="button" class="inline-flex items-center gap-1.5 min-h-[40px] px-2.5 rounded-lg text-sm font-medium text-brand-600 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" @click="editando = true" aria-label="Editar a linha {{ $l->linha_planilha }}"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Editar</button>
                                    <button type="button" class="btn-excluir" data-acao-url="{{ route('notas.excluir', $l) }}" data-confirmar="Excluir mesmo?" data-saindo="self" aria-label="Excluir a linha {{ $l->linha_planilha }}"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>Excluir</button>@endcan
                                </td>
                            </tr>
                            @if ($problema && $l->erros)
                                <tr class="bg-perigo-50/40" x-show="!editando"><td></td><td></td><td colspan="9" class="pb-3 pe-6 text-[13px] text-perigo-700">{{ implode(' ', (array) $l->erros) }}</td></tr>
                            @endif

                            @can('emitir')
                            <tr x-show="editando" x-cloak class="bg-brand-50 border-t border-brand-200">
                                <td colspan="11" class="px-5 sm:px-6 py-4">
                                    <form method="POST" action="{{ route('notas.corrigir', $l) }}" class="space-y-3" data-ajax data-ajax-texto="Salvando…">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="_nota" value="{{ $l->id }}">
                                        <div class="font-medium text-sm">Editando a linha {{ $l->linha_planilha }} <span class="text-ink-600 font-normal">· a mudança vale só para o envio; sua planilha original não é alterada.</span></div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                            <label class="text-xs text-ink-600">CPF ou CNPJ do cliente
                                                <input name="tomador_documento" value="{{ $v('tomador_documento', \App\Support\Documento::formatar($l->tomador_documento)) }}" class="{{ $entrada }} font-mono">
                                            </label>
                                            <label class="text-xs text-ink-600 lg:col-span-2">Nome ou razão social
                                                <input name="tomador_nome" value="{{ $v('tomador_nome', $l->tomador_nome) }}" class="{{ $entrada }}">
                                            </label>
                                            <label class="text-xs text-ink-600">E-mail
                                                <input name="tomador_email" value="{{ $v('tomador_email', $l->tomador_email) }}" class="{{ $entrada }}">
                                            </label>
                                            <label class="text-xs text-ink-600">Código de serviço (6 dígitos)
                                                <input name="codigo_servico" value="{{ $v('codigo_servico', $l->codigo_servico) }}" class="{{ $entrada }} font-mono">
                                            </label>
                                            <label class="text-xs text-ink-600">NBS (9 dígitos, opcional)
                                                <input name="codigo_nbs" value="{{ $v('codigo_nbs', $l->codigo_nbs) }}" class="{{ $entrada }} font-mono">
                                            </label>
                                            <label class="text-xs text-ink-600">Valor (R$)
                                                <input name="valor" value="{{ $v('valor', $l->valor > 0 ? number_format($l->valor, 2, ',', '') : '') }}" class="{{ $entrada }} font-mono">
                                            </label>
                                            <label class="text-xs text-ink-600">Competência (dd/mm/aaaa)
                                                <input name="data_competencia" value="{{ $v('data_competencia', $l->data_competencia?->format('d/m/Y')) }}" class="{{ $entrada }} font-mono">
                                            </label>
                                            <label class="text-xs text-ink-600 sm:col-span-2 lg:col-span-4">Descrição do serviço
                                                <textarea name="descricao" rows="2" class="{{ $entrada }}">{{ $v('descricao', $l->descricao) }}</textarea>
                                            </label>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button class="btn btn-primary btn-sm">Salvar linha</button>
                                            <button type="button" class="btn btn-sm" @click="editando = false">Cancelar</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            @endcan
                        </tbody>
                    @empty
                        <tbody><tr class="tabela-linha"><td colspan="11" class="px-6 py-8 text-center text-ink-700">Nenhuma linha neste filtro.</td></tr></tbody>
                    @endforelse
                </table>
            </div>
            @if ($linhas->hasPages())<div class="px-5 sm:px-6 py-3 border-t border-gray-200">{{ $linhas->links() }}</div>@endif
        </section>
        @endif
    </div>
</x-app-layout>
