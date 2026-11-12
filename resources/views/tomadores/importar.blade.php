<x-app-layout titulo="Importar tomadores">
    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-3">
            <a href="{{ route('tomadores.index') }}" class="link text-sm">← Tomadores (Clientes)</a>
            <h1 class="text-3xl font-semibold">Importar tomadores</h1>
            <p class="text-[15px] text-ink-700">Envie a planilha para cadastrar vários tomadores de uma vez, ou atualizar os que já existem. Nada é emitido nem cobrado.</p>
        </div>

        <div class="flex flex-wrap gap-6 items-start">
            <form method="POST" action="{{ route('tomadores.importar') }}" enctype="multipart/form-data"
                  class="flex-[999_1_560px] min-w-0 space-y-5"
                  data-carregando="Lendo a planilha…"
                  x-data="{ nome: null, tamanho: null, soltando: false, emitente: '{{ $emitentes->first()?->id }}',
                            escolher(f) { if (!f) return; this.nome = f.name; this.tamanho = (f.size / 1024).toFixed(0) + ' KB'; } }">
                @csrf

                @error('planilha')
                    {{-- O alerta que não pode passar batido: grande, vermelho e logo acima do arquivo --}}
                    <div role="alert" x-init="$el.scrollIntoView({ block: 'center', behavior: 'smooth' })"
                         class="alerta-forte rounded-xl bg-perigo-600 text-white px-5 py-4 flex gap-4 items-start shadow-lg">
                        <span class="w-10 h-10 shrink-0 rounded-full bg-white/20 flex items-center justify-center" aria-hidden="true">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                        </span>
                        <div class="space-y-2.5 min-w-0">
                            <div class="text-lg font-semibold leading-snug">Não foi possível usar este arquivo</div>
                            <p class="text-[15px] leading-relaxed">{{ $message }}</p>
                        </div>
                    </div>
                @enderror

                @if ($emitentes->isNotEmpty())
                    <x-campo nome="emitente_modelo" tipo="select" rotulo="Emitente do modelo" x-model="emitente" ajuda="A linha 1 do modelo vem com o CNPJ e a razão social do emitente escolhido. Os tomadores são da conta, não de um emitente só.">
                        @foreach ($emitentes as $e)
                            <option value="{{ $e->id }}">{{ $e->razao_social }} · {{ \App\Support\Documento::formatar($e->cnpj) }}</option>
                        @endforeach
                    </x-campo>
                    <p class="text-sm text-ink-700 -mb-2">Use a planilha no modelo: <a :href="'{{ route('tomadores.modelo') }}?emitente=' + emitente" class="link">baixar modelo deste emitente</a> (a linha 1 vem com o CNPJ e a razão social dele).</p>
                @else
                    <p class="text-sm text-ink-700">Use a planilha no modelo: <a href="{{ route('tomadores.modelo') }}" class="link">baixar modelo</a>.</p>
                @endif

                <label for="planilha"
                       @dragover.prevent="soltando = true" @dragleave.prevent="soltando = false"
                       @drop.prevent="soltando = false; $refs.arquivo.files = $event.dataTransfer.files; escolher($event.dataTransfer.files[0])"
                       :class="soltando ? 'border-brand-600 bg-brand-50' : 'border-gray-400 bg-white'"
                       class="block cursor-pointer border-2 border-dashed rounded-xl px-6 py-12 text-center focus-within:ring-2 focus-within:ring-brand-600">
                    <span class="mx-auto w-14 h-14 rounded-full bg-brand-100 flex items-center justify-center" aria-hidden="true"><svg class="w-6 h-6 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg></span>
                    <span class="block mt-3 text-base font-medium" x-show="!nome">Arraste a planilha aqui ou clique para escolher</span>
                    <span class="block mt-3 text-base font-medium" x-show="nome" x-cloak><span x-text="nome"></span> <span class="text-sm font-normal text-ink-600">· <span x-text="tamanho"></span></span></span>
                    <span class="block mt-1 text-[13px] text-ink-600">.xlsx, .xls ou .csv · até 5 MB · até {{ number_format($limite, 0, ',', '.') }} linhas</span>
                    <input id="planilha" name="planilha" type="file" accept=".xlsx,.xls,.csv" required class="sr-only" x-ref="arquivo" @change="escolher($event.target.files[0])">
                </label>

                <div class="flex flex-wrap items-center gap-3">
                    <button class="btn btn-primary btn-lg">Importar</button>
                    <a href="{{ route('tomadores.index') }}" class="btn btn-lg">Cancelar</a>
                </div>
            </form>

            <aside class="card flex-[1_1_300px] min-w-0 p-5 lg:sticky lg:top-6">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-base font-semibold">Modelo da planilha</h2>
                    <a href="{{ route('tomadores.modelo') }}" class="link text-sm whitespace-nowrap">Baixar</a>
                </div>
                @if ($temVideo)
                    <button type="button" @click="$dispatch('abrir-video')" class="group mt-3 block w-full text-left rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2" aria-label="Assistir ao tutorial em vídeo">
                        <span class="relative block aspect-video overflow-hidden rounded-xl border border-linha bg-chao shadow-[0_6px_18px_rgba(14,42,32,.22)] transition-shadow group-hover:shadow-[0_10px_28px_rgba(14,42,32,.32)]">
                            @if (is_file(public_path('videos/tutorial-planilha-tomadores.jpg')))
                                <img src="{{ asset('videos/tutorial-planilha-tomadores.jpg') }}" alt="" width="800" height="450" loading="lazy" class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-[1.03]">
                            @endif
                            <span class="absolute inset-0 bg-casca/25 group-hover:bg-casca/35 transition-colors" aria-hidden="true"></span>
                            <span class="absolute inset-0 flex items-center justify-center" aria-hidden="true">
                                <span class="w-14 h-14 rounded-full bg-white/95 text-brand-600 shadow-lg flex items-center justify-center transition-transform group-hover:scale-110"><svg class="w-6 h-6 ms-1" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
                            </span>
                        </span>
                        <span class="mt-2 block text-sm font-medium text-ink-900">Ver como importar tomadores</span>
                    </button>
                @endif
                <dl class="mt-3 text-[13px] leading-snug divide-y divide-gray-200">
                    @foreach ([
                        ['Linha 1', 'CNPJ e razão social do emitente. Só identifica a planilha.'],
                        ['Linha 2', 'Cabeçalhos. Os tomadores começam na linha 3.'],
                        ['CNPJ/CPF', 'Obrigatório. Razão Social/Nome é obrigatório para tomador novo.'],
                        ['Já existe?', 'Mesmo CNPJ/CPF atualiza só o que veio preenchido. Célula vazia mantém o cadastro.'],
                        ['Endereço', 'Para emitir nota: logradouro, número, bairro, município, UF, CEP e ao menos 1 e-mail.'],
                        ['Regras completas', 'Estão na aba “Como preencher” do modelo (somente leitura).'],
                    ] as [$col, $info])
                        <div class="py-2 grid grid-cols-[84px_1fr] gap-x-3"><dt class="font-medium text-ink-900">{{ $col }}</dt><dd class="text-ink-700">{{ $info }}</dd></div>
                    @endforeach
                </dl>
                <p class="text-[13px] text-ink-600 mt-3 leading-snug">Linhas com erro não travam a importação: as demais entram e os problemas são listados no final.</p>
            </aside>
        </div>
    </div>

    @if ($temVideo)
        {{-- Player do tutorial: só carrega o vídeo quando o usuário abre --}}
        <div x-data="{ aberto: false }" @abrir-video.window="aberto = true" @keydown.escape.window="aberto = false" x-show="aberto" x-cloak x-transition.opacity.duration.150ms
             class="fixed inset-0 z-[70] flex items-center justify-center p-4 sm:p-8 bg-black/70" role="dialog" aria-modal="true" aria-label="Tutorial de importação de tomadores" @click.self="aberto = false">
            <div class="w-full max-w-[960px] rounded-xl bg-white overflow-hidden shadow-[0_28px_90px_rgba(0,0,0,.6),0_6px_20px_rgba(0,0,0,.35)] ring-1 ring-white/15">
                <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-linha">
                    <h2 class="text-base font-semibold">Como importar tomadores</h2>
                    <button type="button" @click="aberto = false" class="w-10 h-10 -me-1.5 inline-flex items-center justify-center rounded-lg hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" aria-label="Fechar vídeo">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </div>
                <template x-if="aberto">
                    <video class="block w-full aspect-video bg-black" controls autoplay playsinline preload="metadata">
                        <source src="{{ asset('videos/tutorial-planilha-tomadores.mp4') }}" type="video/mp4">
                    </video>
                </template>
            </div>
        </div>
    @endif
</x-app-layout>
