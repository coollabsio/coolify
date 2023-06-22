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
    <main>
        {{ $slot }}
    </main>
    <x-version class="fixed left-2 bottom-1" />
    <script>
        function changePasswordFieldType(event) {
            const element = event.target.parentElement.parentElement.children[0];
            if (element.nodeName === 'INPUT') {
                if (element.type === 'password') {
                    element.type = 'text';
                } else {
                    element.type = 'password';
                }
            }
            if (element.nodeName === 'DIV') {
                if (element.children[0].type === 'password') {
                    element.children[0].type = 'text';
                } else {
                    element.children[0].type = 'password';
                }
            }
            if (element.nodeName === 'svg') {
                if (element.parentElement.parentElement.children[0].type === 'password') {
                    element.parentElement.parentElement.children[0].type = 'text';
                } else {
                    element.parentElement.parentElement.children[0].type = 'password';
                }
            }
        }
    </script>
</body>

</html>
