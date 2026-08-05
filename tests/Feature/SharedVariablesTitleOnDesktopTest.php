<?php

/**
 * Shared variables family: hide the page H1 on desktop; editor view toggle lives in layer-2 nav.
 */
test('shared variables pages hide the family title on desktop', function () {
    $paths = [
        resource_path('views/livewire/shared-variables/index.blade.php'),
        resource_path('views/livewire/shared-variables/project/index.blade.php'),
        resource_path('views/livewire/shared-variables/environment/index.blade.php'),
        resource_path('views/livewire/shared-variables/server/index.blade.php'),
        resource_path('views/components/shared-variables/editor.blade.php'),
    ];

    foreach ($paths as $path) {
        expect(file_get_contents($path))
            ->toContain('section="shared-variables"')
            ->toContain(':titleOnDesktop="false"');
    }
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

test('shared variables navigation uses the standard mobile settings menu', function () {
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    expect($navbar)
        ->toContain("\$stackTabsOnMobile = \$section === 'shared-variables'")
        ->toContain('grid grid-cols-2 gap-0.5 border-y')
        ->toContain("'menu-item'")
        ->toContain("'menu-item-active' => \$item['active']")
        ->toContain("\$sharedVariableIcons[\$item['label']]")
        ->toContain('hidden lg:flex');
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
