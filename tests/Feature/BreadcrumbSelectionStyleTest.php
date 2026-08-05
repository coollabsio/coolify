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
