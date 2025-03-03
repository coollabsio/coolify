<!DOCTYPE html>
<html class="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

@use('App\Models\InstanceSettings')
@php

    $instanceSettings = instanceSettings();
    $isHttps = str(request()->url())->startsWith('https');

    $name = null;

    if ($instanceSettings) {
        $displayName = $instanceSettings->getTitleDisplayName();

        if (strlen($displayName) > 0) {
            $name = $displayName . ' ';
        }
    }
@endphp

<head>
    <meta charset="utf-8">
    @if ($isHttps)
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    @endif
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#ffffff" />
    <meta name="Description" content="Coolify: An open-source & self-hostable Heroku / Netlify / Vercel alternative" />
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

    <title>{{ $name }}{{ $title ?? 'Coolify' }}</title>
    @env('local')
    <link rel="icon" href="{{ asset('coolify-logo-dev-transparent.png') }}" type="image/x-icon" />
@else
    <link rel="icon" href="{{ asset('coolify-transparent.png') }}" type="image/x-icon" />
    @endenv
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/inertia.app.js', 'resources/css/inertia.css'])
    @if (config('app.name') == 'Coolify Cloud')
        <script defer data-domain="app.coolify.io" src="https://analytics.coollabs.io/js/plausible.js"></script>
        <script src="https://js.sentry-cdn.com/0f8593910512b5cdd48c6da78d4093be.min.js" crossorigin="anonymous"></script>
    @endif
    {{-- @auth
        <script type="text/javascript" src="{{ URL::asset('js/echo.js') }}"></script>
        <script type="text/javascript" src="{{ URL::asset('js/pusher.js') }}"></script>
        <script type="text/javascript" src="{{ URL::asset('js/apexcharts.js') }}"></script>
    @endauth --}}

    @inertiaHead

</head>


<body class="min-h-screen bg-background text-foreground">
    @routes
    @inertia
</body>

</html>
