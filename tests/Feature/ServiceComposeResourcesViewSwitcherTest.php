<?php

it('provides grid and table views for compose resources without sorting controls', function () {
    $configuration = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));
    $resourceCard = file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php'));

    expect($configuration)
        ->toContain("localStorage.getItem('service-compose-resources-view') || 'table'")
        ->toContain("setViewMode('table')")
        ->toContain("setViewMode('grid')")
        ->toContain('aria-label="Table view"')
        ->toContain('aria-label="Grid view"')
        ->toContain('mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between')
        ->toContain('flex w-full items-center justify-between gap-2 sm:w-auto sm:justify-start')
        ->toContain("localStorage.setItem('service-compose-resources-view', mode)")
        ->not->toContain('>Sort</button>')
        ->and($resourceCard)
        ->toContain("x-show=\"viewMode === 'grid'\"")
        ->toContain("x-show=\"viewMode === 'table'\"");
});

it('directs compose application domain management to the parent service', function () {
    $resourceSettings = file_get_contents(resource_path('views/livewire/project/service/index.blade.php'));

    expect($resourceSettings)
        ->toContain('Manage domains, DNS checks, and redirects on the parent service')
        ->toContain("route('project.service.domains', \$parameters)")
        ->toContain('Manage domains')
        ->not->toContain('<x-forms.domain-chips model="fqdn" label="Domains"');
});

it('aligns compose resource columns and uses icon actions', function () {
    $configuration = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));
    $resourceCard = file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php'));
    $columns = 'sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem_5rem]';

    expect($configuration)->toContain($columns)
        ->and($resourceCard)->toContain($columns)
        ->toContain('flex flex-wrap items-center justify-end gap-1 sm:contents')
        ->toContain('aria-label="Resource settings"')
        ->toContain('aria-label="Service backups"')
        ->toContain("route('project.service.volume-backups.index', \$parameters)")
        ->not->toContain("route('project.service.database.backups'")
        ->not->toContain('>Settings</a>')
        ->not->toContain('>Backups</a>');
});

it('distinguishes domain management from resource settings', function () {
    $resourceCard = file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php'));

    expect($resourceCard)
        ->toContain('title="Manage domains" aria-label="Manage domains"')
        ->toContain('<x-reicon name="globe" class="size-4" />')
        ->toContain('title="Resource settings" aria-label="Resource settings"')
        ->not->toContain('title="Edit domains" aria-label="Edit domains"');
});

it('does not display application domains on compose resource cards', function () {
    $resourceCard = file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php'));

    expect($resourceCard)
        ->not->toContain('{{ $resource->fqdn }}');
});
