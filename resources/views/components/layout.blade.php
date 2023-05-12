<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
    <a
        class="fixed text-xs cursor-pointer left-2 bottom-1 opacity-20 hover:opacity-100 hover:text-white">v{{ config('version') }}</a>
    @livewireScripts

    @auth
        <x-navbar />
    @endauth
    <main>
        {{ $slot }}
    </main>

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

            function checkIfIamDead() {
                console.log('Checking server\'s pulse...')
                checkIfIamDeadInterval = setInterval(async () => {
                    try {
                        const res = await fetch('/api/health');
                        if (res.ok) {
                            console.log('I\'m alive. Waiting for server to be dead...');
                        }
                    } catch (error) {
                        console.log('I\'m dead. Charging... Standby... Bzz... Bzz...')
                        checkHealth();
                        if (checkIfIamDeadInterval) clearInterval(checkIfIamDeadInterval);
                    }

                    return;
                }, 2000);
            }

            function checkHealth() {
                console.log('Checking server\'s health...')
                checkHealthInterval = setInterval(async () => {
                    try {
                        const res = await fetch('/api/health');
                        if (res.ok) {
                            console.log('Server is back online. Reloading...')
                            if (checkHealthInterval) clearInterval(checkHealthInterval);
                            window.location.reload();
                        }
                    } catch (error) {
                        console.log('Waiting for server to come back from dead...');
                    }

                    return;
                }, 2000);
            }
            Livewire.on('updateInitiated', () => {
                let checkHealthInterval = null;
                let checkIfIamDeadInterval = null;
                console.log('Update initiated. Waiting for server to be dead...')
                checkIfIamDead();
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
