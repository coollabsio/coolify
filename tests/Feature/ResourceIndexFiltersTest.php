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

it('uses stable classes to hide resource table columns on mobile', function () {
    $view = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('class="resource-type"')
        ->toContain('class="resource-server"')
        ->toContain('class="resource-type truncate')
        ->toContain('class="resource-server truncate')
        ->toContain('class="mobile-resource-domain min-w-0"');

    expect($css)
        ->toContain('.environment-resource-grid .resource-type,')
        ->toContain('.environment-resource-grid .resource-server')
        ->toContain('.environment-resource-grid .mobile-resource-domain')
        ->not->toContain('.environment-resource-grid > :nth-child(2),')
        ->not->toContain('.environment-resource-grid > :nth-child(5)');
});
