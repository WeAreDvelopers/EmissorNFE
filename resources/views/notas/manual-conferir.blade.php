<x-app-layout titulo="Conferir nota">
    @php
        $brl = 'R$ '.number_format((float) $dados['valor'], 2, ',', '.');
        $temDuplicidade = (bool) $parecida;
    @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-1">
            <h1 class="text-3xl font-semibold">Conferir antes de emitir</h1>
            <p class="text-[15px] text-ink-700">Nada foi emitido ainda. Confira o resumo; se estiver certo, marque a confirmação e emita.</p>
        </div>

        <form method="POST" action="{{ route('notas.manual.store') }}" class="max-w-3xl space-y-5" data-carregando="Enviando a nota…"
              x-data="{ confirmo: false, outra: false, duplicada: @js($temDuplicidade) }">
            @csrf
            {{-- os campos aninhados (ajustes) voltam como ajustes[campo] --}}
            @foreach (\Illuminate\Support\Arr::dot($campos) as $nome => $valor)
                <input type="hidden" name="{{ preg_replace('/^([^.]+)\.(.+)$/', '$1[$2]', $nome) }}" value="{{ $valor }}">
            @endforeach

            @if ($emitente->ehHomologacao())
                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900"><b>Modo teste.</b> A nota emitida aqui não tem valor fiscal.</div>
            @endif

            @if ($parecida)
                <div role="alert" class="rounded-xl bg-amber-50 border border-amber-300 px-4 py-3 text-sm text-amber-900 space-y-2">
                    <div><b>Atenção:</b> já existe uma nota parecida (mesmo cliente, valor e data): nota #{{ $parecida->id }}, {{ $parecida->status->label() }}@if ($parecida->numero_nfse) · NFS-e nº {{ $parecida->numero_nfse }}@endif.</div>
                    <label class="flex items-center gap-2"><input type="checkbox" name="confirmar_duplicidade" value="1" x-model="outra" class="rounded text-brand-600 focus:ring-brand-600"> É outra cobrança: emitir mesmo assim</label>
                </div>
            @endif

            @error('confirmo')<div role="alert" class="rounded-xl bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900">{{ $message }}</div>@enderror

            <section class="card divide-y divide-linha">
                <div class="p-5 sm:p-6">
                    <div class="text-[13px] text-ink-600">Quem emite</div>
                    <div class="font-medium">{{ $emitente->razao_social }} <span class="font-mono text-ink-600 text-sm">· {{ \App\Support\Documento::formatar($emitente->cnpj) }}</span></div>
                </div>

                <div class="p-5 sm:p-6">
                    <div class="text-[13px] text-ink-600">Para quem</div>
                    @if ($dados['sem_tomador'])
                        <div class="font-medium">Sem destinatário informado</div>
                    @else
                        <div class="font-medium">{{ $dados['tomador_nome'] }}</div>
                        <div class="text-sm text-ink-700"><span class="font-mono">{{ \App\Support\Documento::formatar($dados['tomador_documento']) }}</span>@if ($dados['tomador_email']) · {{ $dados['tomador_email'] }}@endif</div>
                        <div class="text-[13px] text-ink-600 mt-1">{{ $cliente ? 'Cliente já cadastrado.' : 'Cliente novo: será cadastrado com estes dados.' }}</div>
                    @endif
                </div>

                <div class="p-5 sm:p-6 space-y-3">
                    <div>
                        <div class="text-[13px] text-ink-600">Código de serviço @if ($padrao['codigo'])<span class="pill bg-gray-100 text-ink-700 ms-1">padrão da empresa</span>@endif</div>
                        <div><span class="font-mono font-semibold">{{ $dados['codigo_servico'] }}</span>@if ($servico) <span class="text-sm text-ink-700">· {{ $servico }}</span>@endif</div>
                    </div>
                    <div>
                        <div class="text-[13px] text-ink-600">Descrição @if ($padrao['descricao'])<span class="pill bg-gray-100 text-ink-700 ms-1">padrão da empresa</span>@endif</div>
                        <div class="text-[15px] whitespace-pre-line">{{ $dados['descricao'] }}</div>
                    </div>
                    @if ($dados['codigo_nbs'])
                        <div>
                            <div class="text-[13px] text-ink-600">NBS</div>
                            <div class="font-mono">{{ $dados['codigo_nbs'] }}</div>
                        </div>
                    @endif
                </div>

                <div class="p-5 sm:p-6">
                    <div class="text-[13px] text-ink-600">Dados fiscais</div>
                    @if ($ajustes)
                        <div class="font-medium">Diferentes do padrão da empresa, só nesta nota:</div>
                        <ul class="mt-1.5 space-y-1 text-sm">
                            @foreach ($ajustes as [$rotulo, $de, $para])
                                <li><span class="text-ink-700">{{ $rotulo }}:</span> <span class="text-ink-600 line-through">{{ $de }}</span> → <b>{{ $para }}</b></li>
                            @endforeach
                        </ul>
                    @else
                        <div class="font-medium">Padrões da empresa</div>
                        <div class="text-[13px] text-ink-600">Município, regime, tributos aproximados e código municipal como estão no cadastro da empresa.</div>
                    @endif
                </div>

                <div class="p-5 sm:p-6 flex flex-wrap gap-x-10 gap-y-3">
                    <div>
                        <div class="text-[13px] text-ink-600">Valor</div>
                        <div class="text-2xl font-semibold font-mono">{{ $brl }}</div>
                    </div>
                    <div>
                        <div class="text-[13px] text-ink-600">Data de competência</div>
                        <div class="text-lg font-medium">{{ \Carbon\Carbon::parse($dados['data_competencia'])->format('d/m/Y') }}</div>
                    </div>
                </div>
            </section>

            <div class="rounded-lg bg-chao px-3.5 py-3 text-[13px] leading-relaxed text-ink-700">
                @if ($emitente->semCobranca())
                    Esta empresa não gasta crédito para emitir ({{ $emitente->ehHomologacao() ? 'modo teste' : 'sem cobrança' }}).
                @else
                    Ao emitir, 1 crédito é reservado e só é cobrado se a prefeitura autorizar.
                @endif
            </div>

            <label class="flex items-start gap-3 rounded-xl border-2 border-brand-600 bg-white px-4 py-3.5 cursor-pointer">
                <input type="checkbox" name="confirmo" value="1" x-model="confirmo" class="mt-1 w-5 h-5 rounded text-brand-600 focus:ring-brand-600">
                <span class="text-[15px]"><b>Conferi os dados e confirmo a emissão desta nota.</b><span class="block text-[13px] text-ink-600">Depois de emitida, só é possível cancelar a NFS-e.</span></span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button class="btn btn-primary btn-lg disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!confirmo || (duplicada && !outra)">Emitir nota</button>
                <button type="submit" formaction="{{ route('notas.manual.voltar') }}" formnovalidate class="btn btn-lg">Alterar dados</button>
            </div>
        </form>
    </div>
</x-app-layout>
