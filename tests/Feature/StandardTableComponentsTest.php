<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

it('contains wide data tables without overflowing their layout', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain(".data-table {\n    min-width: 0;\n    max-width: 100%;\n    overflow-x: auto;");
});

it('renders the standard table toolbar controls', function () {
    $html = Blade::render(<<<'BLADE'
        <x-table.toolbar>
            <x-slot:search>
                <x-table.search placeholder="Search deployments" wire:model.live="search" />
            </x-slot:search>
            <x-table.filter :active-count="2" reset-action="clearFilters">
                <button class="listbox-option">Success</button>
            </x-table.filter>
            <x-table.sort>
                <button class="listbox-option">Newest first</button>
            </x-table.sort>
        </x-table.toolbar>
    BLADE);

    expect($html)
        ->toContain('table-toolbar')
        ->toContain('table-search')
        ->toContain('wire:model.live="search"')
        ->not->toContain('x-teleport="body"')
        ->not->toContain('floatingDropdown(')
        ->toContain("panelStyle: 'position: fixed; min-width: 0; visibility: hidden;'")
        ->toContain("this.panelStyle = 'position: fixed; min-width: 0; visibility: hidden;'")
        ->toContain('position: fixed')
        ->toContain('min-width: 0')
        ->toContain('getBoundingClientRect()')
        ->toContain('x-show="open"')
        ->toContain('aria-multiselectable="true"')
        ->toContain('Reset filters')
        ->toContain('wire:click="clearFilters"')
        ->toContain('Sort');
});

it('does not load a global floating dropdown positioning provider', function () {
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));

    expect($layout)->not->toContain("@include('components.floating-dropdown-script')")
        ->and(File::exists(resource_path('views/components/floating-dropdown-script.blade.php')))->toBeFalse();
});

it('renders the standard table loading overlay', function () {
    $html = Blade::render('<div class="relative"><x-table.loading target="applyFilters" text="Loading records..." /></div>');

    expect($html)
        ->toContain('table-loading-overlay')
        ->toContain('wire:loading.flex')
        ->toContain('wire:target="applyFilters"')
        ->toContain('[&_.loading-indicator]:size-5')
        ->toContain('aria-label="Loading records..."')
        ->not->toContain('<span>Loading records...</span>');
});

it('uses shared table controls on backend filtered tables', function () {
    $deployments = file_get_contents(resource_path('views/livewire/project/application/deployment/index.blade.php'));
    $environmentVariables = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    foreach ([$deployments, $environmentVariables] as $view) {
        expect($view)
            ->toContain('<x-table.toolbar')
            ->toContain('<x-table.search')
            ->toContain('<x-table.filter')
            ->toContain('<x-table.sort')
            ->toContain('<x-table.loading');
    }
});

it('uses the standard search control for frontend filtered infrastructure tables', function () {
    $controls = file_get_contents(resource_path('views/livewire/shared/list-search-controls.blade.php'));

    expect($controls)
        ->toContain('<x-table.search')
        ->toContain('clear-when="search"')
        ->toContain("clear-action=\"search = ''\"");
});

it('uses the collision aware dropdown on the projects page', function () {
    $projects = file_get_contents(resource_path('views/livewire/project/index.blade.php'));

    expect($projects)
        ->toContain('<x-table.dropdown')
        ->not->toContain('x-show="sortOpen"');
});

it('uses collision aware filter and sort dropdowns on the resources page', function () {
    $resources = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect(substr_count($resources, '<x-table.dropdown'))->toBeGreaterThanOrEqual(2)
        ->and($resources)
        ->not->toContain('x-show="filterOpen"')
        ->not->toContain('x-show="sortOpen"');
});

it('does not use legacy floating sort or filter panels', function () {
    $views = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => $file->getContents())
        ->implode("\n");

    expect($views)
        ->not->toMatch('/x-show="(?:sortOpen|filterOpen|filtersOpen)"/');
});
