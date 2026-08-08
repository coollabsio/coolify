<?php

it('provides dynamic multi-select resource filters', function () {
    $view = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect($view)
        ->toContain('typeFilters: []')
        ->toContain('tagFilters: []')
        ->toContain('serverFilters: []')
        ->toContain('statusFilters: []')
        ->toContain('get filterGroups()')
        ->toContain("label: 'Tags'")
        ->toContain("label: 'Servers'")
        ->toContain("label: 'Statuses'")
        ->toContain('toggleFilter(group.key, option.value)')
        ->toContain('clearFilters()')
        ->toContain('Clear filters')
        ->not->toContain("typeFilter: 'all'");
});
