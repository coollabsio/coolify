<?php

test('view switchers use the shared coollabs selected state on every page', function () {
    $views = collect([
        resource_path('views/livewire/server/index.blade.php'),
        resource_path('views/livewire/project/index.blade.php'),
        resource_path('views/livewire/project/show.blade.php'),
        resource_path('views/livewire/project/resource/index.blade.php'),
        resource_path('views/livewire/project/service/configuration.blade.php'),
        resource_path('views/livewire/tags/show.blade.php'),
    ])->map(fn (string $path): string => file_get_contents($path));
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    expect($views->every(fn (string $view): bool => str_contains($view, "viewMode === 'table'")
        && str_contains($view, "viewMode === 'grid'")
        && str_contains($view, 'control-selected')))
        ->toBeTrue()
        ->and($views->implode("\n"))->not->toContain('dark:bg-warning/15 dark:text-warning')
        ->and($utilities)
        ->toContain('@utility control-selected')
        ->toContain('@apply bg-coollabs text-white dark:bg-coollabs dark:text-white;');
});
