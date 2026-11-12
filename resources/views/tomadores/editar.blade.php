<x-app-layout :titulo="'Tomador · '.$tomador->nome">
    @php $podeEditar = Gate::allows('emitir'); $rotulos = \App\Models\Tomador::CAMPOS; $fmt = fn ($v) => is_array($v) ? implode(', ', $v) : ($v === null || $v === '' ? '—' : $v); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6" x-data="{ historico: false }" @keydown.escape.window="historico = false">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-2">
                <a href="{{ route('tomadores.index') }}" class="link text-sm">← Tomadores (Clientes)</a>
                <h1 class="text-3xl font-semibold">{{ $tomador->nome }}</h1>
                <p class="text-base text-ink-900"><span class="font-mono font-bold">{{ \App\Support\Documento::formatar($tomador->documento) }}</span> <span class="pill align-middle ms-1 {{ $tomador->tipoPessoa() === 'PF' ? 'bg-info-100 text-info-800' : 'bg-gray-100 text-ink-700' }}">{{ $tomador->tipoPessoa() === 'PF' ? 'Pessoa física (PF)' : 'Pessoa jurídica (PJ)' }}</span></p>
            </div>
            <button type="button" class="btn" @click="historico = ! historico" :aria-expanded="historico" aria-controls="historico-alteracoes" title="Ver o histórico de alterações deste cliente">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>
                <span x-text="historico ? 'Ocultar histórico' : 'Histórico de alterações'">Histórico de alterações</span>
                @if ($alteracoes->count())<span class="text-xs rounded-full bg-gray-100 px-2 py-0.5">{{ $alteracoes->count() }}</span>@endif
            </button>
        </div>

        @if ($tomador->pendente)
            <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
                <b>Cadastro incompleto.</b> Faltam: {{ implode(', ', $tomador->faltando()) }}.
            </div>
        @endif

        <div class="flex flex-wrap gap-6 items-start">
            <form method="POST" action="{{ route('tomadores.update', $tomador) }}" class="flex-[999_1_620px] min-w-0 card p-5 sm:p-6 space-y-4">
                @csrf @method('PUT')
                @include('tomadores._campos', ['tomador' => $tomador, 'podeEditar' => $podeEditar, 'criando' => false])
                @if ($errors->any())<div class="rounded-lg bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900" role="alert">{{ $errors->first() }}</div>@endif
                <div class="flex flex-wrap items-center gap-3">
                    @if ($podeEditar)<button class="btn btn-primary btn-lg">Salvar</button>@endif
                    <a href="{{ route('tomadores.index') }}" class="btn btn-lg">{{ $podeEditar ? 'Cancelar' : 'Voltar' }}</a>
                </div>
            </form>

            <aside id="historico-alteracoes" x-show="historico" x-cloak class="flex-[1_1_320px] min-w-0 card p-5 sm:p-6">
                <h2 class="text-base font-semibold">Histórico de alterações</h2>
                @forelse ($alteracoes as $a)
                    <div class="tabela-linha py-3 text-sm">
                        <div class="text-xs text-ink-600">{{ $a->created_at->format('d/m/Y H:i') }} · {{ match ($a->origem) { 'importacao' => 'importação de notas', 'planilha_clientes' => 'planilha de clientes', default => 'edição manual' } }}@if ($a->user) · {{ $a->user->name }}@endif</div>
                        @foreach ($a->campos as $campo => [$de, $para])
                            @if (isset($rotulos[$campo]))
                                <div class="leading-snug"><b>{{ $rotulos[$campo] }}:</b> <span class="text-ink-600">{{ $fmt($de) }}</span> → {{ $fmt($para) }}</div>
                            @endif
                        @endforeach
                    </div>
                @empty
                    <p class="text-sm text-ink-600 mt-2">Sem alterações registradas.</p>
                @endforelse
            </aside>
        </div>
    </div>
</x-app-layout>
