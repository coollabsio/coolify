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
    <x-toaster-hub />
    <x-navbar />
    <div class="fixed top-3 left-4" id="vue">
        <magic-bar></magic-bar>
    </div>
    <main class="main">
        {{ $slot }}
    </main>
    <x-version class="fixed left-2 bottom-1" />
    <script>
    let checkHealthInterval = null;
    let checkIfIamDeadInterval = null;

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

    function revive() {
        if (checkHealthInterval) return true;
        console.log('Checking server\'s health...')
        checkHealthInterval = setInterval(() => {
            fetch('/api/health')
                .then(response => {
                    if (response.ok) {
                        Toaster.success('Coolify is back online. Reloading...')
                        if (checkHealthInterval) clearInterval(checkHealthInterval);
                        setTimeout(() => {
                            window.location.reload();
                        }, 5000)
                    } else {
                        console.log('Waiting for server to come back from dead...');
                    }
                })
            return;
        }, 2000);
    }

    function upgrade() {
        if (checkIfIamDeadInterval) return true;
        console.log('Update initiated.')
        checkIfIamDeadInterval = setInterval(() => {
            fetch('/api/health')
                .then(response => {
                    if (response.ok) {
                        console.log('It\'s alive. Waiting for server to be dead...');
                    } else {
                        Toaster.success('Update done, restarting Coolify!')
                        console.log('It\'s dead. Reviving... Standby... Bzz... Bzz...')
                        if (checkIfIamDeadInterval) clearInterval(checkIfIamDeadInterval);
                        revive();
                    }
                })
            return;
        }, 2000);
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text);
        Livewire.emit('message', 'Copied to clipboard.');
    }
    Livewire.on('reloadWindow', () => {
        window.location.reload();
    })
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
    </script>
    @endauth
    @guest
    {{ $slot }}
    @endguest
</body>

</html>