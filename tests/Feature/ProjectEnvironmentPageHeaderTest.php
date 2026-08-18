<?php

/**
 * Project and environment pages carry their own page header instead of a
 * layer-2 bar. The former `x-project.navbar` rendered a single "Resources"
 * tab that was only ever active on `project.resource.index` (a page that did
 * not render the bar), and rendered nothing at all without an environment.
 */
$projectPages = [
    'views/livewire/project/show.blade.php',
    'views/livewire/project/edit.blade.php',
    'views/livewire/project/environment-edit.blade.php',
    'views/livewire/project/clone-me.blade.php',
];

it('drops the project layer-2 bar that never matched the current route', function () use ($projectPages) {
    expect(file_exists(resource_path('views/components/project/navbar.blade.php')))->toBeFalse();

    foreach ($projectPages as $page) {
        expect(file_get_contents(resource_path($page)))
            ->not->toContain('<x-project.navbar')
            ->not->toContain('resource-heading-navbar');
    }
});

it('gives every project and environment page an in-flow title', function () use ($projectPages) {
    foreach ($projectPages as $page) {
        expect(file_get_contents(resource_path($page)))
            ->toContain('<h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">')
            ->toContain('class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim"');
    }
});

it('keeps the environment clone action in the page header', function () {
    $view = file_get_contents(resource_path('views/livewire/project/environment-edit.blade.php'));

    expect($view)
        ->toContain('{{ $environment->name }}</h1>')
        ->toContain('Environment settings in {{ $project->name }}')
        ->toContain('Clone environment')
        // The action moved out of the card header into the page header.
        ->toMatch('/<header[^>]*>.*Clone environment.*<\/header>/s');
});

it('does not repeat the resources action outside the resources page', function () {
    foreach (['views/livewire/project/environment-edit.blade.php', 'views/livewire/project/clone-me.blade.php'] as $page) {
        expect(file_get_contents(resource_path($page)))->not->toContain('New resource');
    }

    expect(file_get_contents(resource_path('views/livewire/project/resource/index.blade.php')))
        ->toContain('New resource');
});

it('uses a stable class for the environment resource count on mobile', function () {
    $view = file_get_contents(resource_path('views/livewire/project/show.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('class="environment-resource-count">Resources</div>')
        ->toContain('class="environment-resource-count text-[12px]');

    expect($css)
        ->toContain('.environments-table-grid .environment-resource-count')
        ->not->toContain('.environments-table-grid > :nth-child(2)');
});
