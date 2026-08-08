<?php

it('shows a loading overlay while resource selection steps load', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->toContain('wire:loading.class="pointer-events-none opacity-40 blur-[2px]"')
        ->toContain('wire:loading.flex')
        ->toContain('selectServer,selectDestination,selectProject,selectEnvironment')
        ->toContain('Loading selection…')
        ->toContain('isPaletteTransitioning')
        ->toContain('runPaletteTransition')
        ->toContain('Loading…');
});

it('uses a single Alpine result renderer for every command palette result type', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->not->toContain('Create mode (server-rendered path)')
        ->not->toContain('!$wire.isCreateMode')
        ->toContain("<!-- Command palette -->\n    <div x-show=\"modalOpen\"")
        ->not->toContain("<!-- Command palette -->\n    <template x-teleport=\"body\">")
        ->toContain("<div wire:ignore>\n                        <template x-if=\"searchQuery.length")
        ->toContain('x-for="(result, index) in searchResults"')
        ->toContain('x-for="[categoryName, items] in Object.entries(groupedCreatableItems)"');
});

it('skips hidden command palette results during keyboard navigation', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->toContain('filter(item => item.offsetParent !== null)');
});
