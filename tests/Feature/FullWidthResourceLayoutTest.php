<?php

test('the application shell and resource workspaces use the full available width', function () {
    $appLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $applicationConfiguration = file_get_contents(resource_path('views/livewire/project/application/configuration.blade.php'));
    $serverConfiguration = file_get_contents(resource_path('views/livewire/server/show.blade.php'));

    expect($appLayout)
        ->toContain("pageWidth === 'centered' ? 'mx-auto max-w-[1400px]' : 'max-w-none'")
        ->and($applicationConfiguration)
        ->not->toContain('max-w-[1180px]')
        ->toContain('xl:gap-8')
        ->and($serverConfiguration)
        ->not->toContain('max-w-[1180px]')
        ->toContain('xl:gap-8');
});
