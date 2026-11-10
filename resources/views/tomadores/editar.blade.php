<x-app-layout :titulo="'Cliente · '.$tomador->nome">
    @php $podeEditar = Gate::allows('emitir'); $rotulos = \App\Models\Tomador::CAMPOS; $fmt = fn ($v) => is_array($v) ? implode(', ', $v) : ($v === null || $v === '' ? '—' : $v); @endphp

    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-2">
            <a href="{{ route('tomadores.index') }}" class="link text-sm">← Clientes</a>
            <h1 class="text-3xl font-semibold">{{ $tomador->nome }}</h1>
            <p class="text-[15px] text-ink-700"><span class="font-mono">{{ \App\Support\Documento::formatar($tomador->documento) }}</span></p>
        </div>

        @if ($tomador->pendente)
            <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
                <b>Cadastro incompleto.</b> Faltam: {{ implode(', ', $tomador->faltando()) }}. As notas ainda saem, mas sem o endereço do cliente.
            </div>
        @endif

        <div class="flex flex-wrap gap-6 items-start">
            <form method="POST" action="{{ route('tomadores.update', $tomador) }}" class="flex-[999_1_620px] min-w-0 card p-5 sm:p-6 space-y-4">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-campo nome="nome" rotulo="Razão social / nome" :valor="$tomador->nome" required :disabled="! $podeEditar" />
                    <x-campo nome="nome_fantasia" rotulo="Nome fantasia" :valor="$tomador->nome_fantasia" :disabled="! $podeEditar" />
                    <x-campo nome="logradouro" rotulo="Logradouro" :valor="$tomador->logradouro" class="sm:col-span-2" :disabled="! $podeEditar" />
                    <x-campo nome="numero" rotulo="Número" :valor="$tomador->numero" :disabled="! $podeEditar" />
                    <x-campo nome="complemento" rotulo="Complemento" :valor="$tomador->complemento" :disabled="! $podeEditar" />
                    <x-campo nome="bairro" rotulo="Bairro" :valor="$tomador->bairro" :disabled="! $podeEditar" />
                    <x-campo nome="cep" rotulo="CEP" :valor="$tomador->cep" mono :disabled="! $podeEditar" />
                    <x-campo nome="municipio" rotulo="Município" :valor="$tomador->municipio" :disabled="! $podeEditar" :ajuda="$tomador->codigo_municipio ? 'Código IBGE '.$tomador->codigo_municipio : ($tomador->municipio ? 'Município não reconhecido: confira o nome e a UF.' : null)" />
                    <x-campo nome="uf" rotulo="UF" :valor="$tomador->uf" maxlength="2" mono :disabled="! $podeEditar" />
                    <x-campo nome="emails" rotulo="E-mails" :valor="implode(', ', $tomador->emails ?? [])" class="sm:col-span-2" :disabled="! $podeEditar" ajuda="Separe por vírgula. O primeiro é o principal e vai no XML da nota; o aviso de nota emitida vai para todos." />
                    <div class="sm:col-span-2">
                        <label for="observacoes" class="campo-rotulo">Observações</label>
                        <textarea id="observacoes" name="observacoes" rows="3" class="campo-entrada" @disabled(! $podeEditar)>{{ old('observacoes', $tomador->observacoes) }}</textarea>
                    </div>
                </div>
                @if ($errors->any())<div class="rounded-lg bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900" role="alert">{{ $errors->first() }}</div>@endif
                @if ($podeEditar)<button class="btn btn-primary btn-lg">Salvar</button>@endif
            </form>

            <aside class="flex-[1_1_320px] min-w-0 card p-5 sm:p-6">
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
