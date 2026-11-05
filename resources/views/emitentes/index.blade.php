<x-app-layout titulo="Empresas que emitem">
    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Empresas que emitem</h1>
                <p class="mt-1 text-[15px] text-ink-700">Cada empresa tem o seu certificado, série e ambiente.</p>
            </div>
            @can('gerenciar-emitentes')<a href="{{ route('emitentes.criar') }}" class="btn btn-primary btn-lg">+ Cadastrar empresa</a>@endcan
        </div>

        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-ink-600">
                    <tr><th class="px-5 py-3 font-medium">CNPJ</th><th class="px-5 py-3 font-medium">Razão social</th><th class="px-5 py-3 font-medium">Ambiente</th><th class="px-5 py-3 font-medium">Série</th><th class="px-5 py-3 font-medium">Certificado</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($emitentes as $e)
                    <tr class="tabela-linha">
                        <td class="px-5 py-3 whitespace-nowrap font-mono">{{ \App\Support\Documento::formatar($e->cnpj) }}</td>
                        <td class="px-5 py-3"><div class="flex items-center gap-3">@if ($e->logo_url)<span class="w-9 h-9 shrink-0 rounded-md border border-gray-200 bg-white flex items-center justify-center overflow-hidden"><img src="{{ $e->logo_url }}" alt="" class="max-w-full max-h-full object-contain"></span>@endif<span>{{ $e->razao_social }}</span></div></td>
                        <td class="px-5 py-3">@if ($e->ambiente == 1)<span class="pill bg-ok-100 text-ok-800">Produção</span>@else<span class="pill bg-amber-100 text-amber-900">Teste</span>@endif</td>
                        <td class="px-5 py-3 font-mono">{{ $e->serie_dps }}</td>
                        <td class="px-5 py-3">
                            @if (! $e->certificado_validade) <span class="text-warn-800 font-medium">sem certificado</span>
                            @elseif ($e->certificado_validade->isPast()) <span class="text-warn-800 font-medium">vencido em {{ $e->certificado_validade->format('d/m/Y') }}</span>
                            @elseif ($e->certificado_validade->diffInDays(now()) < 30) <span class="text-amber-900 font-medium">vence em {{ $e->certificado_validade->format('d/m/Y') }}</span>
                            @else até {{ $e->certificado_validade->format('d/m/Y') }}
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">@can('gerenciar-emitentes')<a class="link" href="{{ route('emitentes.editar', $e) }}">Editar</a>@endcan</td>
                    </tr>
                @empty
                    <tr class="tabela-linha"><td colspan="6" class="px-5 py-10 text-center text-ink-700">Nenhuma empresa cadastrada ainda.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
