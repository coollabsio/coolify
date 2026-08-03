<?php

/**
 * Centered process dialog used for startup / activity log streams.
 */
test('process dialog shells use display contents so event-only hosts take no layout space', function () {
    $component = file_get_contents(resource_path('views/components/process-dialog.blade.php'));

    expect($component)
        ->toContain("'class' => 'contents'")
        ->toContain('processDialogOpen')
        ->toContain('role="dialog"')
        ->toContain('items-center justify-center')
        ->toContain('min-h-[min(70dvh,28rem)]')
        ->toContain('sm:min-w-[32rem]')
        ->not->toContain('translate-x-full');
});

test('service database and proxy startup use the centered process dialog', function () {
    $paths = [
        resource_path('views/livewire/project/service/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/server/navbar.blade.php'),
    ];

    foreach ($paths as $path) {
        expect(file_get_contents($path))
            ->toContain('<x-process-dialog')
            ->toContain('processDialogOpen = true')
            ->not->toContain('<x-slide-over @start');
    }
});

test('process dialog body styles support a full-height log surface', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.process-dialog')
        ->toContain('.process-dialog-body')
        ->toContain('min-height: min(70dvh, 28rem)');
});
