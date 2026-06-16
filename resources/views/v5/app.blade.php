<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name') }} v5</title>
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
        <script type="module" src="{{ $viteDevServerUrl }}/resources/js/v5/app.jsx"></script>
    @else
        @viteReactRefresh
        @vite('resources/js/v5/app.jsx')
    @endif
    <x-inertia::head />
</head>

<body>
    <x-inertia::app id="v5-app" />
</body>

</html>
