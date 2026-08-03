<?php

/**
 * Programmatic confirmation modals must not leave empty layout rows.
 */
test('modal confirmation roots are inline so hidden triggers do not stretch full width', function () {
    $modal = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));

    expect($modal)
        ->toContain("'relative h-auto max-w-full'")
        ->toContain("'inline-flex w-auto' => ! \$buttonFullWidth")
        ->toContain("'flex w-full' => \$buttonFullWidth")
        ->not->toContain("'relative h-auto'");
});

test('server mobile proxy confirmations sit inside a display-none host', function () {
    $navbar = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));

    expect($navbar)
        ->toContain('server-mobile-restart-proxy-trigger')
        ->toContain('server-mobile-stop-proxy-trigger')
        ->toMatch('/class="hidden"[^>]*>\s*<x-modal-confirmation title="Confirm Proxy Restart/');
});

test('application database and service programmatic modals are layout-hidden', function () {
    foreach ([
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
    ] as $path) {
        expect(file_get_contents($path))
            ->toContain('class="hidden" aria-hidden="true"')
            ->toContain('<x-slot:trigger>');
    }
});

test('slide-over event shells use display contents', function () {
    expect(file_get_contents(resource_path('views/components/slide-over.blade.php')))
        ->toContain("'class' => 'contents'");
});

test('process dialog event shells use display contents', function () {
    expect(file_get_contents(resource_path('views/components/process-dialog.blade.php')))
        ->toContain("'class' => 'contents'");
});
