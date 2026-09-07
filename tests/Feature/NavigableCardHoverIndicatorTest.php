<?php

it('uses card hover animation without a right arrow on project and server cards', function () {
    $views = [
        resource_path('views/livewire/project/index.blade.php'),
        resource_path('views/livewire/server/index.blade.php'),
    ];

    foreach ($views as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->toContain('hover:-translate-y-px')
            ->toContain('hover:shadow-md')
            ->not->toContain('group-hover:translate-x-0.5 group-hover:opacity-100')
            ->not->toContain('pointer-events-none absolute top-1/2 right-3');
    }
});
