<?php

use Illuminate\Support\Facades\Blade;

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
        ->toContain('aria-multiselectable="true"')
        ->toContain('Reset filters')
        ->toContain('wire:click="clearFilters"')
        ->toContain('Sort');
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
