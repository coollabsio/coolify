<!DOCTYPE html>
<html data-theme="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://api.fonts.coollabs.io" crossorigin>
    <link rel="dns-prefetch" href="https://api.fonts.coollabs.io" />
    <link rel="preload" href="https://api.fonts.coollabs.io/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        as="style" />
    <link rel="preload" href="https://cdn.fonts.coollabs.io/inter/normal/400.woff2" as="style" />
    <link rel="preload" href="https://cdn.fonts.coollabs.io/inter/normal/500.woff2" as="style" />
    <link rel="preload" href="https://cdn.fonts.coollabs.io/inter/normal/600.woff2" as="style" />
    <link rel="preload" href="https://cdn.fonts.coollabs.io/inter/normal/700.woff2" as="style" />
    <link rel="preload" href="https://cdn.fonts.coollabs.io/inter/normal/800.woff2" as="style" />
    <link href="https://api.fonts.coollabs.io/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'Coolify' }}</title>
    @env('local')
    <link rel="icon" href="{{ asset('favicon-dev.png') }}" type="image/x-icon" />
@else
    <link rel="icon" href="{{ asset('coolify-transparent.png') }}" type="image/x-icon" />
    @endenv
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/app.js', 'resources/css/app.css'])
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
    @if (config('app.name') == 'Coolify Cloud')
        <script defer data-domain="app.coolify.io" src="https://analytics.coollabs.io/js/plausible.js"></script>
    @endif
    @auth
        <script src="https://cdnjs.cloudflare.com/ajax/libs/laravel-echo/1.15.3/echo.iife.min.js"
            integrity="sha512-aPAh2oRUr3ALz2MwVWkd6lmdgBQC0wSr0R++zclNjXZreT/JrwDPZQwA/p6R3wOCTcXKIHgA9pQGEQBWQmdLaA=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.3.0/pusher.min.js"
            integrity="sha512-tXL5mrkSoP49uQf2jO0LbvzMyFgki//znmq0wYXGq94gVF6TU0QlrSbwGuPpKTeN1mIjReeqKZ4/NJPjHN1d2Q=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    @endauth
</head>
@section('body')

    <body>
        <x-toast />
        <script data-navigate-once>
            if (!('theme' in localStorage)) {
                localStorage.theme = 'dark';
                document.documentElement.classList.add('dark')
            } else if (localStorage.theme === 'dark') {
                document.documentElement.classList.add('dark')
            } else if (localStorage.theme === 'light') {
                document.documentElement.classList.remove('dark')
            } else {
                if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark')
                } else {
                    document.documentElement.classList.remove('dark')
                }
            }
            @auth
            window.Pusher = Pusher;
            window.Echo = new Echo({
                broadcaster: 'pusher',
                cluster: "{{ env('PUSHER_HOST') }}" || window.location.hostname,
                key: "{{ env('PUSHER_APP_KEY') }}" || 'coolify',
                wsHost: "{{ env('PUSHER_HOST') }}" || window.location.hostname,
                wsPort: "{{ getRealtime() }}",
                wssPort: "{{ getRealtime() }}",
                forceTLS: false,
                encrypted: true,
                enableStats: false,
                enableLogging: true,
                enabledTransports: ['ws', 'wss'],
            });
            @endauth
            let checkHealthInterval = null;
            let checkIfIamDeadInterval = null;

            function changePasswordFieldType(event) {
                let element = event.target
                for (let i = 0; i < 10; i++) {
                    if (element.className === "relative") {
                        break;
                    }
                    element = element.parentElement;
                }
                element = element.children[1];
                if (element.nodeName === 'INPUT' || element.nodeName === 'TEXTAREA') {
                    if (element.type === 'password') {
                        element.type = 'text';
                        if (element.disabled) return;
                        element.classList.add('truncate');
                        this.type = 'text';
                    } else {
                        element.type = 'password';
                        if (element.disabled) return;
                        element.classList.remove('truncate');
                        this.type = 'password';
                    }
                }
            }

            function copyToClipboard(text) {
                navigator?.clipboard?.writeText(text) && window.Livewire.dispatch('success', 'Copied to clipboard.');
            }
            document.addEventListener('livewire:init', () => {
                window.Livewire.on('reloadWindow', (timeout) => {
                    if (timeout) {
                        setTimeout(() => {
                            window.location.reload();
                        }, timeout);
                        return;
                    } else {
                        window.location.reload();
                    }
                })
                window.Livewire.on('info', (message) => {
                    if (typeof message === 'string') {
                        window.toast('Info', {
                            type: 'info',
                            description: message,
                        })
                        return;
                    }
                    if (message.length == 1) {
                        window.toast('Info', {
                            type: 'info',
                            description: message[0],
                        })
                    } else if (message.length == 2) {
                        window.toast(message[0], {
                            type: 'info',
                            description: message[1],
                        })
                    }
                })
                window.Livewire.on('error', (message) => {
                    if (typeof message === 'string') {
                        window.toast('Error', {
                            type: 'danger',
                            description: message,
                        })
                        return;
                    }
                    if (message.length == 1) {
                        window.toast('Error', {
                            type: 'danger',
                            description: message[0],
                        })
                    } else if (message.length == 2) {
                        window.toast(message[0], {
                            type: 'danger',
                            description: message[1],
                        })
                    }
                })
                window.Livewire.on('warning', (message) => {
                    if (typeof message === 'string') {
                        window.toast('Warning', {
                            type: 'warning',
                            description: message,
                        })
                        return;
                    }
                    if (message.length == 1) {
                        window.toast('Warning', {
                            type: 'warning',
                            description: message[0],
                        })
                    } else if (message.length == 2) {
                        window.toast(message[0], {
                            type: 'warning',
                            description: message[1],
                        })
                    }
                })
                window.Livewire.on('success', (message) => {
                    if (typeof message === 'string') {
                        window.toast('Success', {
                            type: 'success',
                            description: message,
                        })
                        return;
                    }
                    if (message.length == 1) {
                        window.toast('Success', {
                            type: 'success',
                            description: message[0],
                        })
                    } else if (message.length == 2) {
                        window.toast(message[0], {
                            type: 'success',
                            description: message[1],
                        })
                    }
                })
            });
        </script>
    </body>
@show

</html>
