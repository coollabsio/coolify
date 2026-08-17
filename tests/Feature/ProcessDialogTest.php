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

test('runtime log terminal fills the available dialog width', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/get-logs.blade.php'));

    expect($view)->toContain("<div @class(['w-full min-w-0', 'runtime-log-shell' => \$collapsible])>");
});

test('process and log viewers use centered dialogs', function () {
    $paths = [
        resource_path('views/livewire/project/service/index.blade.php'),
        resource_path('views/livewire/project/database/keydb/general.blade.php'),
        resource_path('views/livewire/project/database/redis/general.blade.php'),
        resource_path('views/livewire/project/database/postgresql/general.blade.php'),
        resource_path('views/livewire/project/database/clickhouse/general.blade.php'),
        resource_path('views/livewire/project/database/mongodb/general.blade.php'),
        resource_path('views/livewire/project/database/dragonfly/general.blade.php'),
        resource_path('views/livewire/project/database/mariadb/general.blade.php'),
        resource_path('views/livewire/project/database/mysql/general.blade.php'),
        resource_path('views/livewire/server/security/patches.blade.php'),
        resource_path('views/livewire/server/cloudflare-tunnel.blade.php'),
    ];

    foreach ($paths as $path) {
        expect(file_get_contents($path))
            ->toContain('<x-process-dialog')
            ->not->toContain('<x-slide-over');
    }

    expect(file_get_contents(resource_path('views/livewire/server/show.blade.php')))
        ->not->toContain('<x-slide-over');
});

test('process dialog can start open for an in-progress operation', function () {
    $component = file_get_contents(resource_path('views/components/process-dialog.blade.php'));

    expect($component)
        ->toContain("'open' => false")
        ->toContain('processDialogOpen: @js($open)');
});
