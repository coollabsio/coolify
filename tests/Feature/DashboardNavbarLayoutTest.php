<?php

test('settings header navigation is replaced by the settings sidebar', function () {
    $path = resource_path('views/components/dashboard/navbar.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain("['label' => 'General', 'route' => 'settings.index', 'active' => request()->routeIs('settings.*')]")
        ->not->toContain("['label' => 'Email', 'route' => 'settings.email'");
});

test('dashboard navbar keeps the original side-by-side tab and actions layout', function () {
    $path = resource_path('views/components/dashboard/navbar.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between')
        ->toContain('flex min-w-0 w-full items-center gap-0.5 overflow-x-auto');
});
