<x-app-layout titulo="Nova importação">
    @php $emTeste = $emitentes->contains('ambiente', 2); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-3">
            <a href="{{ route('importacoes.index') }}" class="link text-sm">← Importações</a>
            <h1 class="text-3xl font-semibold">Nova importação</h1>
            <x-passos :atual="1" />
            <p class="text-[15px] text-ink-700">Envie a planilha. Primeiro você confere o resumo; só depois enviamos. Nada é emitido nem cobrado antes disso.</p>
        </div>

        @if ($emitentes->isEmpty())
            <div class="card p-6">
                <p class="text-ink-700">Cadastre a empresa que vai emitir as notas para poder importar.</p>
                @can('gerenciar-emitentes')<a href="{{ route('emitentes.criar') }}" class="btn btn-primary mt-4">Cadastrar empresa</a>@endcan
            </div>
        @else
        <div class="flex flex-wrap gap-6 items-start">
            <form method="POST" action="{{ route('importacoes.store') }}" enctype="multipart/form-data"
                  class="flex-[999_1_560px] min-w-0 space-y-5"
                  data-carregando="Lendo a planilha…"
                  x-data="{ nome: null, tamanho: null, soltando: false, emitente: '{{ old('emitente_id', $emitentes->first()->id) }}',
                            logos: @js($emitentes->mapWithKeys(fn ($e) => [$e->id => $e->logo_url])),
                            escolher(f) { if (!f) return; this.nome = f.name; this.tamanho = (f.size / 1024).toFixed(0) + ' KB'; } }">
                @csrf

                @if ($emTeste)
                    <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900 flex gap-3">
                        <svg class="w-5 h-5 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                        <span><b>Modo teste.</b> As notas emitidas aqui não têm valor fiscal. Troque para produção na configuração da empresa quando os testes passarem.</span>
                    </div>
                @endif

                @error('planilha')
                    {{-- O alerta que não pode passar batido: grande, vermelho e logo acima do arquivo --}}
                    <div role="alert" x-init="$el.scrollIntoView({ block: 'center', behavior: 'smooth' })"
                         class="alerta-forte rounded-xl bg-perigo-600 text-white px-5 py-4 flex gap-4 items-start shadow-lg">
                        <span class="w-10 h-10 shrink-0 rounded-full bg-white/20 flex items-center justify-center" aria-hidden="true">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                        </span>
                        <div class="space-y-2.5 min-w-0">
                            <div class="text-lg font-semibold leading-snug">{{ session('pedir_confirmacao_repetida') ? 'Atenção: esta planilha já foi enviada' : 'Não foi possível usar este arquivo' }}</div>
                            <p class="text-[15px] leading-relaxed">{{ $message }}</p>
                            @if (session('pedir_confirmacao_repetida'))
                                <label class="flex items-center gap-2.5 text-[15px] font-medium bg-white/15 rounded-lg px-3.5 py-3 cursor-pointer">
                                    <input type="checkbox" name="confirmar_repetida" value="1" class="rounded w-5 h-5 text-perigo-700 focus:ring-white"> Enviar mesmo assim (as notas serão geradas em dobro)
                                </label>
                            @endif
                        </div>
                    </div>
                @enderror

                <div x-show="logos[emitente]" x-cloak class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3">
                    <span class="w-14 h-14 shrink-0 rounded-lg border border-gray-200 flex items-center justify-center overflow-hidden"><img :src="logos[emitente]" alt="Logo da empresa" class="max-w-full max-h-full object-contain"></span>
                    <span class="text-sm text-ink-700">As notas desta importação serão emitidas por esta empresa.</span>
                </div>

                <x-campo nome="emitente_id" tipo="select" rotulo="Empresa que emite" x-model="emitente">
                    @foreach ($emitentes as $e)
                        <option value="{{ $e->id }}" @selected(old('emitente_id') == $e->id)>{{ $e->razao_social }} · {{ \App\Support\Documento::formatar($e->cnpj) }}</option>
                    @endforeach
                </x-campo>

                <p class="text-sm text-ink-700 -mb-2">Use a planilha no modelo da empresa: <a :href="'{{ route('importacoes.modelo') }}?emitente=' + emitente" class="link">baixar modelo desta empresa</a> (já vem com o CNPJ na célula A1).</p>

                <label for="planilha"
                       @dragover.prevent="soltando = true" @dragleave.prevent="soltando = false"
                       @drop.prevent="soltando = false; $refs.arquivo.files = $event.dataTransfer.files; escolher($event.dataTransfer.files[0])"
                       :class="soltando ? 'border-brand-600 bg-brand-50' : 'border-gray-400 bg-white'"
                       class="block cursor-pointer border-2 border-dashed rounded-xl px-6 py-12 text-center focus-within:ring-2 focus-within:ring-brand-600">
                    <span class="mx-auto w-14 h-14 rounded-full bg-brand-100 flex items-center justify-center" aria-hidden="true"><svg class="w-6 h-6 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg></span>
                    <span class="block mt-3 text-base font-medium" x-show="!nome">Arraste a planilha aqui ou clique para escolher</span>
                    <span class="block mt-3 text-base font-medium" x-show="nome" x-cloak><span x-text="nome"></span> <span class="text-sm font-normal text-ink-600">· <span x-text="tamanho"></span></span></span>
                    <span class="block mt-1 text-[13px] text-ink-600">.xlsx, .xls ou .csv · até 10 MB</span>
                    <input id="planilha" name="planilha" type="file" accept=".xlsx,.xls,.csv" required class="sr-only" x-ref="arquivo" @change="escolher($event.target.files[0])">
                </label>

                <div class="flex items-center gap-4">
                    <button class="btn btn-primary btn-lg">Ler e conferir →</button>
                    <a href="{{ route('importacoes.index') }}" class="text-sm text-ink-700">Cancelar</a>
                </div>
            </form>

            <aside class="card flex-[1_1_320px] min-w-0 p-5 sm:p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-semibold">Como a planilha funciona</h2>
                    <a href="{{ route('importacoes.modelo') }}" class="link text-sm">Baixar modelo</a>
                </div>
                <p class="text-[13px] text-ink-700 mt-2">É o modelo de notas dos ERPs. O app procura a aba certa sozinho (o nome muda a cada mês).</p>
                <dl class="mt-3 text-sm divide-y divide-gray-200">
                    @foreach ([
                        ['A1', 'CNPJ da empresa que emite · tem que bater com a empresa escolhida'],
                        ['E1 e F1', 'totais de conferência · avisamos se não baterem com as linhas'],
                        ['Linha 2', 'cabeçalhos · as notas começam na linha 3'],
                        ['CNPJ/CPF', 'do cliente, ou “sem destinatário informado”'],
                        ['Atualizar', 'com x, as colunas de dados novos (fantasia, razão social, endereço, e-mail) atualizam o cadastro do cliente; campo vazio mantém o que já existe'],
                        ['Descrição', 'opcional · vazia usa a descrição padrão da empresa'],
                        ['Código de Serviço e NBS', 'opcionais, para empresas com vários serviços · vazios usam o padrão da empresa'],
                    ] as [$col, $info])
                        <div class="py-2"><dt class="font-medium text-[13px]">{{ $col }}</dt><dd class="text-ink-600 text-[13px]">{{ $info }}</dd></div>
                    @endforeach
                </dl>
                <p class="text-[13px] text-ink-700 mt-3">Linhas com erro não travam o lote: você corrige na tela de conferência e o resto segue. O cadastro de clientes só muda quando você confirmar o envio.</p>
            </aside>
        </div>
        @endif
    </div>
</x-app-layout>
