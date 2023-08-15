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
    <main>
        {{ $slot }}
    </main>
    <x-version class="fixed left-2 bottom-1" />
    <script>
        Livewire.on('info', (message) => {
            if (message) Toaster.info(message)
        })
        Livewire.on('error', (message) => {
            if (message) Toaster.error(message)
        })
        Livewire.on('warning', (message) => {
            if (message) Toaster.warning(message)
        })
        Livewire.on('success', (message) => {
            if (message) Toaster.success(message)
        })

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
    </script>

</body>

</html>
