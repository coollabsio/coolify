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
        </script>
    @endauth
</body>

</html>
