<?php

/**
 * Settings / resource side navs must stay pinned while the main form column scrolls.
 */
it('pins application-settings-navigation with sticky CSS that survives body overflow-x', function () {
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($appCss)
        ->toContain('overflow-x-clip')
        ->not->toContain('scrollbar overflow-x-hidden;')
        ->toContain('.application-settings-navigation')
        ->toContain('position: sticky')
        ->toContain('top: 6.5rem')
        ->toContain('max-height: calc(100dvh - 7.25rem)')
        ->toContain('overflow-y: auto');
});

it('marks shared settings sidebars sticky at the xl breakpoint', function () {
    $paths = [
        resource_path('views/livewire/project/application/configuration.blade.php'),
        resource_path('views/livewire/project/database/configuration.blade.php'),
        resource_path('views/livewire/project/service/configuration.blade.php'),
        resource_path('views/components/settings/sidebar.blade.php'),
        resource_path('views/components/server/sidebar.blade.php'),
        resource_path('views/components/service-database/sidebar.blade.php'),
    ];

    foreach ($paths as $path) {
        expect(file_get_contents($path))
            ->toContain('application-settings-navigation')
            ->toContain('xl:sticky')
            ->toContain('xl:top-26')
            ->toContain('xl:self-start');
    }
});
