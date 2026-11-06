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
                            <div class="text-lg font-semibold leading-snug">{{ session('importacao_repetida') ? 'Este arquivo já foi importado' : 'Não foi possível usar este arquivo' }}</div>
                            <p class="text-[15px] leading-relaxed">{{ $message }}</p>
                            @if (session('importacao_repetida'))
                                <a href="{{ route('importacoes.show', session('importacao_repetida')) }}" class="inline-flex items-center min-h-[44px] px-4 rounded-lg bg-white text-perigo-700 font-semibold no-underline hover:bg-gray-100">Abrir a importação existente →</a>
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

            <aside class="card flex-[1_1_300px] min-w-0 p-5 lg:sticky lg:top-6">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-base font-semibold">Modelo da planilha</h2>
                    <a href="{{ route('importacoes.modelo') }}" class="link text-sm whitespace-nowrap">Baixar</a>
                </div>
                <button type="button" @click="$dispatch('abrir-video')" class="mt-3 w-full flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 hover:bg-brand-100 px-3.5 py-3 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                    <span class="w-10 h-10 shrink-0 rounded-full bg-brand-600 text-white flex items-center justify-center" aria-hidden="true"><svg class="w-4 h-4 ms-0.5" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4.5v15l13-7.5z"/></svg></span>
                    <span class="leading-tight"><b class="block text-sm text-ink-900">Ver como importar</b><span class="text-[13px] text-ink-700">Vídeo de 1 min 27 s</span></span>
                </button>
                <dl class="mt-3 text-[13px] leading-snug divide-y divide-gray-200">
                    @foreach ([
                        ['A1', 'CNPJ da empresa que emite. Precisa bater com a empresa escolhida.'],
                        ['E1 e F1', 'Totais de conferência. Avisamos se não baterem com as linhas.'],
                        ['Linha 2', 'Cabeçalhos. As notas começam na linha 3.'],
                        ['CNPJ/CPF', 'Do cliente, ou “sem destinatário informado”.'],
                        ['Atualizar', 'Com x, as colunas de dados novos atualizam o cadastro do cliente. Campo vazio mantém o que já existe.'],
                        ['Descrição, código e NBS', 'Opcionais. Vazios usam o padrão da empresa.'],
                    ] as [$col, $info])
                        <div class="py-2 grid grid-cols-[84px_1fr] gap-x-3"><dt class="font-medium text-ink-900">{{ $col }}</dt><dd class="text-ink-700">{{ $info }}</dd></div>
                    @endforeach
                </dl>
                <p class="text-[13px] text-ink-600 mt-3 leading-snug">Linhas com erro não travam o lote. O cadastro de clientes só muda quando você confirma o envio.</p>
            </aside>
        </div>
        @endif
    </div>

    {{-- Player do tutorial: só carrega o vídeo quando o usuário abre --}}
    <div x-data="{ aberto: false }" @abrir-video.window="aberto = true" @keydown.escape.window="aberto = false" x-show="aberto" x-cloak x-transition.opacity.duration.150ms
         class="fixed inset-0 z-[70] flex items-center justify-center p-4 sm:p-8 bg-black/70" role="dialog" aria-modal="true" aria-label="Tutorial de importação" @click.self="aberto = false">
        <div class="w-full max-w-[960px] rounded-xl bg-white overflow-hidden shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-linha">
                <h2 class="text-base font-semibold">Como importar a planilha</h2>
                <button type="button" @click="aberto = false" class="w-10 h-10 -me-1.5 inline-flex items-center justify-center rounded-lg hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Fechar vídeo">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <template x-if="aberto">
                <video class="block w-full aspect-video bg-black" controls autoplay playsinline preload="metadata">
                    <source src="{{ asset('videos/tutorial-planilha-importacao.mp4') }}" type="video/mp4">
                    Seu navegador não toca este vídeo. <a href="{{ asset('videos/tutorial-planilha-importacao.mp4') }}">Baixe o vídeo</a>.
                </video>
            </template>
        </div>
    </div>
</x-app-layout>
