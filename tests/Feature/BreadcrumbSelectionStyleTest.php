<?php

test('breadcrumb switchers indicate the current item with background only', function () {
    $views = collect([
        resource_path('views/components/top-breadcrumb.blade.php'),
        resource_path('views/components/breadcrumb-switcher.blade.php'),
        resource_path('views/livewire/switch-team.blade.php'),
    ])->map(fn (string $path): string => file_get_contents($path));

    expect($views->implode("\n"))
        ->not->toContain('name="check-circle"')
        ->and($views->every(fn (string $view): bool => str_contains($view, 'bg-neutral-100')))
        ->toBeTrue();
});

test('teleported resource breadcrumbs use the same spacing as application breadcrumbs', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('class="flex items-center gap-0.5 min-w-0 flex-1 pl-3 pr-4"')
        ->not->toContain('class="flex items-center gap-1.5 min-w-0 flex-1 pl-3 pr-4"');
});
