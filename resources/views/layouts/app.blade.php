<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $titulo ?? config('saas.nome_produto', 'Emissor NFS-e') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="min-h-screen lg:flex" x-data="{ menu: false }">
            @include('layouts.navigation')

            <main class="conteudo flex-1 min-w-0">
                @php $mostrarErros = $errors->any() && ! request()->routeIs('profile.*') && ! request()->routeIs('importacoes.criar'); @endphp
                @if (session('ok') || $mostrarErros)
                    <div class="max-w-[1160px] mx-auto px-4 sm:px-8 mt-4 space-y-2" aria-live="polite">
                        @if (session('ok'))
                            <div class="rounded-lg bg-ok-50 border border-brand-200 text-ok-800 px-4 py-3 text-sm">{{ session('ok') }}</div>
                        @endif
                        @if ($mostrarErros)
                            <div class="rounded-lg bg-warn-50 border border-warn-200 text-warn-900 px-4 py-3 text-sm" role="alert">
                                <ul class="list-disc ps-5">@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                            </div>
                        @endif
                    </div>
                @endif
                {{ $slot }}
            </main>

            {{-- Pop-up de atenção: erros de validação e avisos aparecem em destaque, não só no topo da página --}}
            @php
                $mensagensPopup = collect();
                if ($mostrarErros) { $mensagensPopup = collect($errors->all()); }
                $avisoPopup = session('aviso');
                $confirmacao = session('confirmacao');
                $destinoOk = session('confirmacao_destino');
            @endphp
            @if ($mensagensPopup->isNotEmpty() || $avisoPopup || $confirmacao)
                <div x-data="{ aberto: true }" x-show="aberto" x-cloak @keydown.escape.window="aberto = false"
                     class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50" role="alertdialog" aria-modal="true" aria-labelledby="popup-titulo">
                    <div @click.outside="aberto = false" class="w-full max-w-md rounded-2xl bg-white shadow-2xl p-6 space-y-4">
                        <div class="flex items-start gap-3">
                            <span class="w-10 h-10 shrink-0 rounded-full {{ $mensagensPopup->isNotEmpty() ? 'bg-warn-100 text-warn-800' : ($confirmacao ? 'bg-ok-100 text-ok-800' : 'bg-amber-100 text-amber-800') }} flex items-center justify-center" aria-hidden="true">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 17h.01"/></svg>
                            </span>
                            <div class="min-w-0 space-y-2">
                                <h2 id="popup-titulo" class="text-lg font-semibold text-ink-900">{{ $mensagensPopup->isNotEmpty() ? 'Atenção: confira antes de continuar' : ($confirmacao ? 'Tudo certo' : 'Atenção') }}</h2>
                                @if ($mensagensPopup->isNotEmpty())
                                    <ul class="list-disc ps-5 text-[15px] text-ink-800 space-y-1">@foreach ($mensagensPopup as $erro)<li>{{ $erro }}</li>@endforeach</ul>
                                @endif
                                @if ($confirmacao)<p class="text-[15px] text-ink-800">{{ $confirmacao }}</p>@endif
                                @if ($avisoPopup)<p class="text-[15px] text-ink-800">{{ $avisoPopup }}</p>@endif
                            </div>
                        </div>
                        <div class="flex justify-end">
                            @if ($destinoOk)
                                <a href="{{ $destinoOk }}" class="btn btn-primary" x-init="$el.focus()">OK</a>
                            @else
                                <button type="button" @click="aberto = false" class="btn btn-primary" x-init="$el.focus()">{{ $confirmacao ? 'OK' : 'Entendi' }}</button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </body>
</html>
