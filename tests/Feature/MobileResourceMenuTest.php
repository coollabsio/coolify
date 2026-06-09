<?php

it('uses native mobile menus for databases and services', function () {
    $applicationHeading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $databaseHeading = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));
    $serviceHeading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    expect($applicationHeading)
        ->toContain("'route' => 'project.application.command'")
        ->toContain("'navigate' => false")
        ->toContain("value.startsWith('location|')")
        ->toContain('window.location.href = url');

    expect($databaseHeading)
        ->toContain('database-mobile-section')
        ->toContain('<optgroup label="Database">')
        ->toContain('<optgroup label="Configuration">')
        ->toContain('<optgroup label="Actions">')
        ->toContain("'route' => 'project.database.command'")
        ->toContain("'navigate' => false")
        ->toContain("value.startsWith('location|')")
        ->toContain('window.location.href = url')
        ->toContain('window.Livewire?.navigate ? window.Livewire.navigate(url) : window.location.href = url')
        ->toContain("window.Livewire?.hook?.('morphed'")
        ->toContain('x-model="selected"')
        ->toContain('database-restart-trigger')
        ->toContain('database-stop-trigger')
        ->toContain('scrollbar hidden min-h-10')
        ->not->toContain('<optgroup label="Links">')
        ->not->toContain('@selected');

    expect($serviceHeading)
        ->toContain('service-mobile-section')
        ->toContain('<optgroup label="Service">')
        ->toContain('<optgroup label="Configuration">')
        ->toContain('<optgroup label="Resource">')
        ->toContain('<optgroup label="Links">')
        ->toContain('<optgroup label="Actions">')
        ->toContain("'route' => 'project.service.command'")
        ->toContain("'navigate' => false")
        ->toContain("value.startsWith('location|')")
        ->toContain('window.location.href = url')
        ->toContain('window.Livewire?.navigate ? window.Livewire.navigate(url) : window.location.href = url')
        ->toContain("window.Livewire?.hook?.('morphed'")
        ->toContain('x-model="selected"')
        ->toContain('service-restart-trigger')
        ->toContain('service-stop-trigger')
        ->toContain('service-forceDeploy-trigger')
        ->toContain('service-pullAndRestart-trigger')
        ->toContain('scrollbar hidden min-h-10')
        ->toContain('mb-4 w-full md:mb-0 md:hidden')
        ->toContain('hidden flex-wrap items-center gap-2 md:flex')
        ->not->toContain('order-first flex flex-wrap items-center gap-2 sm:order-last')
        ->not->toContain('@selected');
});

it('keeps configuration sidebars hidden until desktop breakpoint', function () {
    expect(file_get_contents(resource_path('views/livewire/project/database/configuration.blade.php')))
        ->toContain('sub-menu-wrapper hidden md:flex');

    expect(file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php')))
        ->toContain('sub-menu-wrapper hidden md:flex');

    expect(file_get_contents(resource_path('views/livewire/project/service/index.blade.php')))
        ->toContain('sub-menu-wrapper hidden md:flex');

    expect(file_get_contents(resource_path('views/components/service-database/sidebar.blade.php')))
        ->toContain('sub-menu-wrapper hidden md:flex');
});
