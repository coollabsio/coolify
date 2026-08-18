<?php

test('shared variables pages use the shared variables submenu instead of horizontal tabs', function () {
    $paths = [
        resource_path('views/livewire/shared-variables/index.blade.php'),
        resource_path('views/livewire/shared-variables/project/index.blade.php'),
        resource_path('views/livewire/shared-variables/environment/index.blade.php'),
        resource_path('views/livewire/shared-variables/server/index.blade.php'),
        resource_path('views/components/shared-variables/editor.blade.php'),
    ];

    foreach ($paths as $path) {
        expect(file_get_contents($path))
            ->toContain('<x-shared-variables.layout')
            ->not->toContain('section="shared-variables"');
    }

    $layout = file_get_contents(resource_path('views/components/shared-variables/layout.blade.php'));

    expect($layout)
        ->toContain("'Overview'")
        ->toContain("'Team'")
        ->toContain("'Projects'")
        ->toContain("'Environments'")
        ->toContain("'Servers'")
        ->toContain("request()->routeIs('shared-variables.project.*')")
        ->toContain('xl:grid-cols-[210px_minmax(0,1fr)]')
        ->toContain("'menu-item-active' => \$menuItem['active']");
});

test('shared variables are not registered as dashboard tabs', function () {
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    expect($navbar)
        ->not->toContain("'shared-variables' => [")
        ->not->toContain('$stackTabsOnMobile')
        ->not->toContain('$sharedVariableIcons');
});

test('shared variable collection pages provide search and persistent grid and list views', function () {
    $paths = [
        resource_path('views/livewire/shared-variables/project/index.blade.php'),
        resource_path('views/livewire/shared-variables/environment/index.blade.php'),
        resource_path('views/livewire/shared-variables/server/index.blade.php'),
    ];

    foreach ($paths as $path) {
        expect(file_get_contents($path))
            ->toContain('<x-shared-variables.view-controls')
            ->toContain("x-show=\"viewMode === 'grid'\"")
            ->toContain("x-show=\"viewMode === 'list'\"")
            ->toContain('matches(');
    }

    $controls = file_get_contents(resource_path('views/components/shared-variables/view-controls.blade.php'));

    expect($controls)
        ->toContain('placeholder="Search {{ strtolower($label) }}"')
        ->toContain('aria-label="List view"')
        ->toContain('aria-label="Grid view"')
        ->toContain("localStorage.setItem('{{ \$storageKey }}'");
});

test('shared variables editor places the view toggle in the variables section title', function () {
    $editor = file_get_contents(resource_path('views/components/shared-variables/editor.blade.php'));

    expect($editor)
        ->toContain('<x-application.settings-section :title="$title" flush>')
        ->toContain('<x-slot:actions>')
        ->toContain('wire:click="switch"')
        ->toContain('Developer view')
        ->toContain('Normal view')
        ->toMatch('/settings-section[\s\S]{0,300}<x-slot:actions>[\s\S]{0,300}wire:click="switch"/')
        ->not->toContain('actionsInTitle');
});

test('shared variables table omits resource-only flag columns', function () {
    $editor = file_get_contents(resource_path('views/components/shared-variables/editor.blade.php'));
    $show = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    // Shared table: Name / Scope / Comment / Multiline only (no Literal, Buildtime, Runtime).
    expect($editor)
        ->toContain('env-table-grid-shared')
        ->toContain('>Multiline</span>')
        ->not->toContain('>Literal</span>')
        ->not->toContain('>Buildtime</span>')
        ->not->toContain('>Runtime</span>');

    expect($show)
        ->toContain('env-table-grid-shared')
        ->toContain('$isSharedVariable');

    expect($css)->toContain('.env-table-grid-shared');
});
