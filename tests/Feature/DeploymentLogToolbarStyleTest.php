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

test('log search hides lines that do not match', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/\.logs-viewer-line\.hidden\s*\{[^}]*display:\s*none;/s');
});

test('turning off follow logs stays off at the bottom of the log viewer', function () {
    $views = [
        resource_path('views/livewire/project/application/deployment/show.blade.php'),
        resource_path('views/livewire/project/shared/get-logs.blade.php'),
    ];

    foreach ($views as $view) {
        expect(file_get_contents($view))
            ->toContain('followManuallyDisabled: false')
            ->toContain('!this.followManuallyDisabled && distanceFromBottom <= 10')
            ->toContain('this.followManuallyDisabled = true')
            ->toContain('this.followManuallyDisabled = false');
    }
});

test('runtime log follow loop is cleaned up when navigating away', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/get-logs.blade.php'));

    expect($view)
        ->toContain('scrollTimeout: null')
        ->toContain("this.\$root.querySelector('#logsContainer')")
        ->toContain('destroy() {')
        ->toContain('this.destroyed = true')
        ->toContain('this.cancelScrollLoop()');
});
