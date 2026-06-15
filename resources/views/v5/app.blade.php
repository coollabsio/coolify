<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name') }} v5</title>
    @viteReactRefresh
    @vite('resources/js/v5/app.jsx')
    <x-inertia::head />
</head>

<body>
    <x-inertia::app id="v5-app" />
</body>

</html>
