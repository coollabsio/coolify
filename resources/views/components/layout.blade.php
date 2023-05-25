<!DOCTYPE html>
<html data-theme="coollabs" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://api.fonts.coollabs.io" crossorigin>
    <link href="https://api.fonts.coollabs.io/css2?family=Inter&display=swap" rel="stylesheet">
    @env('local')
    <title>Coolify - localhost</title>
    <link rel="icon" href="{{ asset('favicon-dev.png') }}" type="image/x-icon" />
    @endenv
    @env('production')
    <link rel="icon" href="{{ asset('favicon.png') }}" type="image/x-icon" />
    <title>{{ $title ?? 'Coolify' }}</title>
    @endenv
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/app.js', 'resources/css/app.css'])
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
    @livewireStyles
</head>

<body>
    @livewireScripts
    @auth
        <x-navbar />
    @endauth
    <div class="flex justify-center w-full pt-4 min-h-12">
        <x-magic-bar />
    </div>
    <main>
        {{ $slot }}
    </main>
    <livewire:upgrading />
    <a
        class="fixed text-xs cursor-pointer right-2 bottom-1 opacity-60 hover:opacity-100 hover:text-white">v{{ config('version') }}</a>
    @auth
        <script>
            window.addEventListener("keydown", function(event) {
                if (event.target.nodeName === 'BODY') {
                    if (event.key === '/') {
                        event.preventDefault();
                        window.dispatchEvent(new CustomEvent('slash'));
                    }
                }
            })
            Livewire.on('reloadWindow', () => {
                window.location.reload();
            })
            Livewire.on('error', (message) => {
                console.log(message);
                alert(message);
            })
            Livewire.on('saved', (message) => {
                if (message) console.log(message);
                else console.log('saved');
            })
        </script>
    @endauth

</body>

</html>
