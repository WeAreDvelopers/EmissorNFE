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
        </div>
    </body>
</html>
