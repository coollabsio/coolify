<?php

test('active deployment log controls use a coollabs background and white icon', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/\.logs-viewer-btn-active\s*\{[^}]*background:\s*var\(--color-coollabs\);[^}]*color:\s*#fff;/s')
        ->toMatch('/\.dark \.logs-viewer-btn-active\s*\{[^}]*color:\s*#fff;/s')
        ->not->toMatch('/\.logs-viewer-btn-active\s*\{[^}]*var\(--color-warning\)/s');
});

test('deployment logs use light surfaces in light mode and dark surfaces in dark mode', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('bg-white text-neutral-800')
        ->toContain('dark:bg-log dark:text-neutral-100')
        ->toContain('border-neutral-200 shadow-sm dark:border-coolgray-200')
        ->toContain('border-neutral-200! bg-white!')
        ->toContain('dark:border-white/[0.08]! dark:bg-white/[0.05]!')
        ->and($styles)
        ->toMatch('/\.logs-viewer\s*\{[^}]*background:\s*#fff;[^}]*color:\s*#262626;/s')
        ->toMatch('/\.dark \.logs-viewer\s*\{[^}]*background:\s*var\(--color-log\);/s');
});
