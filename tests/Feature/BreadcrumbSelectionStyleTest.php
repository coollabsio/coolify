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

test('top breadcrumbs shrink and clip instead of overlapping on narrower screens', function () {
    $breadcrumb = file_get_contents(resource_path('views/components/top-breadcrumb.blade.php'));
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($breadcrumb)
        ->toContain('class="flex w-full min-w-0 items-center gap-0.5 text-[13px]"')
        ->not->toContain('overflow-hidden text-[13px]')
        ->toContain('class="min-w-0 shrink" x-data="{ collapsed: false }"')
        ->not->toContain('class="shrink-0" x-data="{ collapsed: false }"')
        ->and($layout)
        ->toContain('class="relative flex min-w-0 flex-1 items-center"')
        ->not->toContain('flex min-w-0 flex-1 items-center overflow-hidden')
        ->not->toContain('<div class="flex-1"></div>');
});

test('resource headings do not duplicate database and service breadcrumbs', function () {
    $headings = collect([
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
    ])->map(fn (string $path): string => file_get_contents($path));

    expect($headings->implode("\n"))->not->toContain("@teleport('#server-topbar-context')");
});

test('top breadcrumb lets users switch between resources in the current environment', function () {
    $breadcrumb = file_get_contents(resource_path('views/components/top-breadcrumb.blade.php'));

    expect($breadcrumb)
        ->toContain('$currentEnvironment->applications')
        ->toContain('$currentEnvironment->databases()')
        ->toContain('$currentEnvironment->services')
        ->toContain('<x-breadcrumb-switcher title="Resources"')
        ->toContain("'application' => route('project.application.configuration'")
        ->toContain("'database' => route('project.database.configuration'")
        ->toContain("'service' => route('project.service.configuration'");
});

test('breadcrumb switchers let users search their items', function () {
    $switcher = file_get_contents(resource_path('views/components/breadcrumb-switcher.blade.php'));

    expect($switcher)
        ->toContain('x-model.debounce.150ms="search"')
        ->toContain('type="search"')
        ->toContain('placeholder="Search {{ strtolower($title) }}"')
        ->toContain('class="searchable-listbox-search"')
        ->toContain('class="searchable-listbox-search-input"')
        ->toContain('<x-reicon name="search"')
        ->toContain('left-4 size-3')
        ->toContain('.includes(search.toLowerCase())');
});
