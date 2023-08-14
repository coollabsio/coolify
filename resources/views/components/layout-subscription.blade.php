<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://api.fonts.coollabs.io" crossorigin>
    <link href="https://api.fonts.coollabs.io/css2?family=Inter&display=swap" rel="stylesheet">
    @env('local')
    <title>Coolify - localhost</title>
    @endenv
    @env('production')
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
    <x-toaster-hub />
    @if (auth()->user()->isInstanceAdmin() || is_subscription_in_grace_period())
        <div class="fixed top-3 left-4" id="vue">
            <magic-bar></magic-bar>
        </div>
        <x-navbar />
    @else
        <x-navbar-subscription />
    @endif

    <main class="main max-w-screen-2xl">
        {{ $slot }}
    </main>
    <x-version class="fixed left-2 bottom-1" />
    <script>
        function changePasswordFieldType(event) {
            let element = event.target
            for (let i = 0; i < 10; i++) {
                if (element.className === "relative") {
                    break;
                }
                element = element.parentElement;
            }
            element = element.children[1];
            if (element.nodeName === 'INPUT') {
                if (element.type === 'password') {
                    element.type = 'text';
                } else {
                    element.type = 'password';
                }
            }
        }

        Livewire.on('reloadWindow', () => {
            window.location.reload();
        })
    </script>
</body>

</html>
