<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('saas.nome_produto', 'Emissor NFS-e') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="min-h-screen flex flex-col sm:justify-center items-center py-8 px-4">
            <a href="/" class="flex items-center gap-3 mb-6">
                <span class="w-10 h-10 rounded-lg bg-brand-600 text-white flex items-center justify-center font-semibold text-lg" aria-hidden="true">N</span>
                <span class="font-semibold text-xl">{{ config('saas.nome_produto', 'Emissor NFS-e') }}</span>
            </a>

            <div class="w-full sm:max-w-md card px-6 py-6 shadow-sm">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
