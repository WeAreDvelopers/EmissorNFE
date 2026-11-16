{{-- Cabeçalho: a empresa que está sendo administrada (logo, nome, CNPJ, ambiente) e a troca de empresa.
     Notas, importações e tomadores mostrados abaixo são só dela. --}}
@php
    $cabEmpresa = auth()->check() && ! request()->routeIs('master.*') ? app(\App\Support\Tenancy\EmitenteAtual::class)->emitente() : null;
    $cabEmpresas = $cabEmpresa ? \App\Models\Emitente::where('ativo', true)->orderBy('razao_social')->get() : collect();
@endphp
@if ($cabEmpresa)
    <header class="sticky top-14 lg:top-0 z-30 bg-white/95 backdrop-blur border-b border-linha" aria-label="Empresa que você está administrando">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-8 py-2.5 flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
            <div class="flex items-center gap-3 min-w-0">
                <span class="w-11 h-11 shrink-0 rounded-lg border border-linha bg-white flex items-center justify-center overflow-hidden text-casca font-semibold text-lg" aria-hidden="true">
                    @if ($cabEmpresa->logo_url)<img src="{{ $cabEmpresa->logo_url }}" alt="" class="max-w-full max-h-full object-contain">@else{{ \Illuminate\Support\Str::of($cabEmpresa->razao_social)->substr(0, 1)->upper() }}@endif
                </span>
                <div class="min-w-0 leading-tight">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-brand-600">Empresa que você administra</div>
                    @if ($cabEmpresas->count() > 1)
                        {{-- O nome é o próprio gatilho da troca: um select invisível cobre o texto e a setinha --}}
                        <form method="POST" action="{{ route('emitentes.administrar', ['emitente' => $cabEmpresa->id]) }}" data-base="{{ route('emitentes.administrar', ['emitente' => '__ID__']) }}" class="relative inline-flex items-center gap-1 max-w-full rounded-md hover:bg-gray-100 focus-within:ring-2 focus-within:ring-brand-600">
                            @csrf
                            <span class="text-base font-semibold text-ink-900 truncate max-w-[420px]" title="{{ $cabEmpresa->razao_social }}">{{ $cabEmpresa->razao_social }}</span>
                            <svg class="w-4 h-4 shrink-0 text-ink-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                            <label for="trocar-empresa" class="sr-only">Trocar de empresa</label>
                            <select id="trocar-empresa" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                    onchange="this.form.action = this.form.dataset.base.replace('__ID__', this.value); this.form.submit();">
                                @foreach ($cabEmpresas as $e)
                                    <option value="{{ $e->id }}" @selected($e->id === $cabEmpresa->id)>{{ $e->razao_social }}</option>
                                @endforeach
                            </select>
                        </form>
                    @else
                        <div class="text-base font-semibold text-ink-900 truncate max-w-[420px]" title="{{ $cabEmpresa->razao_social }}">{{ $cabEmpresa->razao_social }}</div>
                    @endif
                    <div class="font-mono text-sm text-ink-700">{{ \App\Support\Documento::formatar($cabEmpresa->cnpj) }}</div>
                </div>
            </div>

            @if ($cabEmpresa->ambiente == 2)
                <span class="pill bg-amber-100 text-amber-900" title="As notas emitidas em modo teste não têm valor fiscal">Modo teste · sem valor fiscal</span>
            @else
                <span class="pill bg-ok-100 text-ok-800" title="Notas com valor fiscal">Produção</span>
            @endif
        </div>
    </header>
@endif
