<x-app-layout titulo="Usuários da conta">
    @php
        $descricao = [
            'dono' => 'Tudo, inclusive usuários e empresas',
            'financeiro' => 'Compra créditos e vê o extrato',
            'operador' => 'Importa planilhas e emite',
            'leitura' => 'Só consulta e baixa',
        ];
    @endphp
    <div class="max-w-[960px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div>
            <h1 class="text-3xl font-semibold">Usuários da conta</h1>
            <p class="mt-1 text-[15px] text-ink-700">Quem pode usar esta conta e o que cada pessoa pode fazer.</p>
        </div>

        <div class="card p-5 sm:p-6">
            <h2 class="text-lg font-semibold mb-3">Convidar por e-mail</h2>
            <form method="POST" action="{{ route('usuarios.convidar') }}" class="grid grid-cols-1 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] gap-4 items-end">
                @csrf
                <x-campo nome="email" tipo="email" rotulo="E-mail da pessoa" required />
                <x-campo nome="papel" tipo="select" rotulo="O que ela pode fazer">
                    @foreach (\App\Enums\Papel::cases() as $p)<option value="{{ $p->value }}" @selected($p === \App\Enums\Papel::Operador)>{{ $p->label() }} — {{ $descricao[$p->value] }}</option>@endforeach
                </x-campo>
                <button class="btn btn-primary">Enviar convite</button>
            </form>
            @if ($convites->isNotEmpty())
                <p class="text-sm text-ink-600 mt-4">Convites pendentes: {{ $convites->pluck('email')->implode(', ') }}</p>
            @endif
        </div>

        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-ink-600"><tr><th class="px-5 py-3 font-medium">Nome</th><th class="px-5 py-3 font-medium">E-mail</th><th class="px-5 py-3 font-medium">Papel</th><th></th></tr></thead>
                <tbody>
                @foreach ($usuarios as $u)
                    <tr class="tabela-linha">
                        <td class="px-5 py-3">{{ $u->name }}</td>
                        <td class="px-5 py-3">{{ $u->email }}</td>
                        <td class="px-5 py-3">
                            <form method="POST" action="{{ route('usuarios.papel', $u->id) }}">
                                @csrf @method('PATCH')
                                <select name="papel" aria-label="Papel de {{ $u->name }}" class="rounded-lg border-gray-400 text-sm min-h-[40px] pe-8" onchange="this.form.submit()">
                                    @foreach (\App\Enums\Papel::cases() as $p)<option value="{{ $p->value }}" @selected($u->pivot->papel === $p->value)>{{ $p->label() }}</option>@endforeach
                                </select>
                            </form>
                        </td>
                        <td class="px-5 py-3 text-right">
                            @if ($u->id !== auth()->id())
                                <form method="POST" action="{{ route('usuarios.remover', $u->id) }}" onsubmit="return confirm('Remover {{ $u->name }} da conta?')">
                                    @csrf @method('DELETE')<button class="text-warn-800 font-medium hover:underline">Remover</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
