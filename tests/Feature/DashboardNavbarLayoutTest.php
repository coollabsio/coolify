<?php

test('settings navbar uses a short email tab label', function () {
    $path = resource_path('views/components/dashboard/navbar.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain("['label' => 'Email', 'route' => 'settings.email'")
        ->not->toContain("['label' => 'Transactional Email', 'route' => 'settings.email'");
});

test('dashboard navbar keeps the original side-by-side tab and actions layout', function () {
    $path = resource_path('views/components/dashboard/navbar.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('flex w-full items-center justify-between gap-4 lg:h-full')
        ->toContain('flex min-w-0 flex-1 items-center gap-0.5 overflow-x-auto')
        ->not->toContain('flex w-full flex-col gap-3 lg:h-full lg:flex-row');
});
