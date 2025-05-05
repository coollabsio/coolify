<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex">
    <title>Coolify</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>

<body>
    @inertia
</body>

</html>
