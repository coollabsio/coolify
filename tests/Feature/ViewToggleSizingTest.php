<?php

it('uses the shared button-height view toggle on every list and grid page', function () {
    $paths = [
        'views/components/shared-variables/view-controls.blade.php',
        'views/livewire/project/index.blade.php',
        'views/livewire/project/resource/index.blade.php',
        'views/livewire/project/service/configuration.blade.php',
        'views/livewire/project/show.blade.php',
        'views/livewire/server/index.blade.php',
        'views/livewire/shared/list-search-controls.blade.php',
        'views/livewire/tags/show.blade.php',
    ];

    foreach ($paths as $path) {
        $contents = file_get_contents(resource_path($path));

        expect($contents)
            ->toContain('class="view-toggle')
            ->not->toContain('class="flex h-9 items-center rounded-lg border');
    }

    $utilities = file_get_contents(resource_path('css/utilities.css'));

    expect($utilities)
        ->toContain('@utility view-toggle')
        ->toContain('h-8');
});
