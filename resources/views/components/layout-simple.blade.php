<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://api.fonts.coollabs.io" crossorigin>
    <link href="https://api.fonts.coollabs.io/css2?family=Inter&display=swap" rel="stylesheet">
    {{ dd(request()->schemeAndHttpHost(), str_starts_with(request()->schemeAndHttpHost(), 'https')) }}
    @if (str_starts_with(request()->schemeAndHttpHost(), 'https'))
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    @else
        http
    @endif
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
</head>

<body>
    <main>
        {{ $slot }}
    </main>
    <a
        class="fixed text-xs cursor-pointer left-2 bottom-1 opacity-20 hover:opacity-100 hover:text-white">v{{ config('version') }}</a>
</body>

</html>
