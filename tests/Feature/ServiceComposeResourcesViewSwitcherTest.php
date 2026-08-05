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
