<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name') }} v5</title>
    @env('local')
        <link rel="icon" href="{{ asset('coolify-logo-dev-transparent.png') }}" type="image/png" />
    @else
        <link rel="icon" href="{{ asset('coolify-logo.svg') }}" type="image/svg+xml" />
    @endenv
    @auth
        <script type="text/javascript" src="{{ URL::asset('js/echo.js') }}"></script>
        <script type="text/javascript" src="{{ URL::asset('js/pusher.js') }}"></script>
        <script>
            window.Pusher = Pusher;
            const EchoConstructor = typeof Echo === 'function' ? Echo : Echo.default;
            window.Echo = new EchoConstructor({
                broadcaster: 'pusher',
                cluster: "{{ config('constants.pusher.host') }}" || window.location.hostname,
                key: "{{ config('constants.pusher.app_key') }}" || 'coolify',
                wsHost: "{{ config('constants.pusher.host') }}" || window.location.hostname,
                wsPort: "{{ getRealtime() }}",
                wssPort: "{{ getRealtime() }}",
                forceTLS: false,
                encrypted: true,
                enableStats: false,
                enableLogging: @json(app()->environment('local')),
                enabledTransports: ['ws', 'wss'],
                disabledTransports: ['sockjs', 'xhr_streaming', 'xhr_polling'],
            });
        </script>
    @endauth
    @php
        $viteHotFile = public_path('hot');
        $viteDevServerUrl = null;

        if (file_exists($viteHotFile)) {
            $viteHotUrl = trim(file_get_contents($viteHotFile));
            $viteScheme = parse_url($viteHotUrl, PHP_URL_SCHEME) ?: request()->getScheme();
            $vitePort = parse_url($viteHotUrl, PHP_URL_PORT) ?: 5173;
            $viteHost = request()->getHost();

            $viteDevServerUrl = "{$viteScheme}://{$viteHost}:{$vitePort}";
        }
    @endphp
    @if ($viteDevServerUrl)
        <script type="module">
            import RefreshRuntime from '{{ $viteDevServerUrl }}/@react-refresh'
            RefreshRuntime.injectIntoGlobalHook(window)
            window.$RefreshReg$ = () => {}
            window.$RefreshSig$ = () => (type) => type
            window.__vite_plugin_react_preamble_installed__ = true
        </script>
        <script type="module" src="{{ $viteDevServerUrl }}/@@vite/client"></script>
        <script type="module" src="{{ $viteDevServerUrl }}/resources/js/v5/app.tsx"></script>
    @else
        @viteReactRefresh
        @vite('resources/js/v5/app.tsx')
    @endif
    <x-inertia::head />
</head>

<body class="overflow-y-scroll">
    <x-inertia::app id="v5-app" />
</body>

</html>
