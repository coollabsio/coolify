<!DOCTYPE html>
<html data-theme="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#ffffff" />
    <meta name="Description" content="Coolify: An open-source & self-hostable Heroku / Netlify / Vercel alternative" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@coolifyio" />
    <meta name="twitter:title" content="Coolify" />
    <meta name="twitter:description" content="An open-source & self-hostable Heroku / Netlify / Vercel alternative." />
    <meta name="twitter:image" content="https://cdn.coollabs.io/assets/coolify/og-image.png" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://coolify.io" />
    <meta property="og:title" content="Coolify" />
    <meta property="og:description" content="An open-source & self-hostable Heroku / Netlify / Vercel alternative." />
    <meta property="og:site_name" content="Coolify" />
    <meta property="og:image" content="https://cdn.coollabs.io/assets/coolify/og-image.png" />
    @use('App\Models\InstanceSettings')
    @php

        $instanceSettings = instanceSettings();
        $name = null;

        if ($instanceSettings) {
            $displayName = $instanceSettings->getTitleDisplayName();

            if (strlen($displayName) > 0) {
                $name = $displayName . ' ';
            }
        }
    @endphp
    <title>{{ $name }}{{ $title ?? 'Coolify' }}</title>
    @env('local')
    <link rel="icon" href="{{ asset('coolify-logo-dev-transparent.png') }}" type="image/x-icon" />
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
        <script src="https://js.sentry-cdn.com/0f8593910512b5cdd48c6da78d4093be.min.js" crossorigin="anonymous"></script>
    @endif
    @auth
        <script type="text/javascript" src="{{ URL::asset('js/echo.js') }}"></script>
        <script type="text/javascript" src="{{ URL::asset('js/pusher.js') }}"></script>
        <script type="text/javascript" src="{{ URL::asset('js/apexcharts.js') }}"></script>
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
            let theme = localStorage.theme
            let baseColor = '#FCD452'
            let textColor = '#ffffff'
            let editorBackground = '#181818'
            let editorTheme = 'blackboard'

            function checkTheme() {
                theme = localStorage.theme
                if (theme == 'dark') {
                    baseColor = '#FCD452'
                    textColor = '#ffffff'
                    editorBackground = '#181818'
                    editorTheme = 'blackboard'
                } else {
                    baseColor = 'black'
                    textColor = '#000000'
                    editorBackground = '#ffffff'
                    editorTheme = null
                }
            }
            @auth
            window.Pusher = Pusher;
            window.Echo = new Echo({
                broadcaster: 'pusher',
                cluster: "{{ config('constants.pusher.host') }}" || window.location.hostname,
                key: "{{ config('constants.pusher.app_key') }}" || 'coolify',
                wsHost: "{{ config('constants.pusher.host') }}" || window.location.hostname,
                wsPort: "{{ getRealtime() }}",
                wssPort: "{{ getRealtime() }}",
                forceTLS: false,
                encrypted: true,
                enableStats: false,
                enableLogging: true,
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
                // Add auto reconnection settings
                enabledTransports: ['ws', 'wss'],
                disabledTransports: ['sockjs', 'xhr_streaming', 'xhr_polling'],
                // Attempt to reconnect on connection lost
                autoReconnect: true,
                // Wait 1 second before first reconnect attempt
                reconnectionDelay: 1000,
                // Maximum delay between reconnection attempts
                maxReconnectionDelay: 1000,
                // Multiply delay by this number for each reconnection attempt
                reconnectionDelayGrowth: 1,
                // Maximum number of reconnection attempts
                maxAttempts: 15
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
