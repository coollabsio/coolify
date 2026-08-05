<?php

it('shows the shared hover arrow on project and server cards', function () {
    $views = [
        resource_path('views/livewire/project/index.blade.php'),
        resource_path('views/livewire/server/index.blade.php'),
    ];

    foreach ($views as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->toContain('<x-reicon name="arrow-right"')
            ->toContain('group-hover:translate-x-0.5 group-hover:opacity-100')
            ->toContain('pointer-events-none absolute top-1/2 right-3');
    }
});
