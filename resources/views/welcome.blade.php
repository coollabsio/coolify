<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Coolify' }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/app.js', 'resources/css/app.css'])
</head>

<body class="antialiased">
    <h1 class="text-3xl font-bold">
        Coolify v4
    </h1>

    <p class="mt-4">
        <a href="/demo"> See demo </a>
    </p>
</body>

</html>
