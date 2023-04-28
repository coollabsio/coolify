<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Coolify' }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/app.js', 'resources/css/app.css'])
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
    @livewireStyles
</head>

<body x-data="confirmModal">
    @auth
        <x-navbar />
    @endauth
    <main>
        {{ $slot }}
    </main>

    <x-confirm-modal />
    @livewireScripts
    @auth
        <script>
            Livewire.on('updateInitiated', () => {
                let checkStatus = null;
                console.log('Update initiated')
                setInterval(async () => {
                    const res = await fetch('/api/health');
                    if (res.ok) {
                        console.log('Server is back online')
                        clearInterval(checkStatus);
                        window.location.reload();
                    } else {
                        console.log('Waiting for server to come back online...');
                    }
                    return;
                }, 2000);
            })
        </script>
    @endauth
</body>

</html>
