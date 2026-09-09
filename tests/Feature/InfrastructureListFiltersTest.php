<?php

it('provides search and persisted grid and table views for infrastructure lists', function (string $view, string $item, string $storageKey) {
    $contents = file_get_contents(resource_path($view));

    expect($contents)
        ->toContain("'placeholder' => 'Search {$item}'")
        ->toContain("localStorage.getItem('{$storageKey}') || 'table'")
        ->toContain("localStorage.setItem('{$storageKey}', mode)")
        ->toContain("@include('livewire.shared.list-search-empty'");
})->with([
    'sources' => ['views/source/all.blade.php', 'sources', 'coolify-sources-view'],
    'destinations' => ['views/livewire/destination/index.blade.php', 'destinations', 'coolify-destinations-view'],
    'S3 storages' => ['views/livewire/storage/index.blade.php', 'S3 storages', 'coolify-s3-storages-view'],
]);

it('renders shared search controls with result counts and view switchers', function () {
    $controls = file_get_contents(resource_path('views/livewire/shared/list-search-controls.blade.php'));
    $emptyState = file_get_contents(resource_path('views/livewire/shared/list-search-empty.blade.php'));

    expect($controls)
        ->toContain('x-model.debounce.150ms="search"')
        ->toContain('filteredItems.length')
        ->toContain("setViewMode('table')")
        ->toContain("setViewMode('grid')")
        ->toContain('aria-label="Table view"')
        ->toContain('aria-label="Grid view"')
        ->toContain('control-selected')
        ->and($emptyState)->toContain('filteredItems.length === 0');
});
