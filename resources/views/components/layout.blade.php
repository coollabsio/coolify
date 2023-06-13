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
    <div class="fixed top-3 left-4" id="vue">
        <magic-bar></magic-bar>
    </div>
    <main>
        {{ $slot }}
    </main>
    <x-version class="fixed left-2 bottom-1" />
    @auth
        <script>
            function changePasswordFieldType(id) {
                const input = document.getElementById(id);
                if (input.type === 'password') {
                    input.type = 'text';
                } else {
                    input.type = 'password';
                }
            }

            function copyToClipboard(text) {
                navigator.clipboard.writeText(text);
                Livewire.emit('message', 'Copied to clipboard.');
            }
            Livewire.on('reloadWindow', () => {
                window.location.reload();
            })
            Livewire.on('error', (message) => {
                console.log(message);
                alert(message);
            })
            Livewire.on('message', (message) => {
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
