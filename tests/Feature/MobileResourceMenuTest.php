<?php

it('uses native mobile menus for databases and services', function () {
    $applicationHeading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $databaseHeading = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));
    $serviceHeading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $applicationMobileActions = mobileActionsMarkup($applicationHeading, 'application-mobile-actions', 'application-mobile-section');
    $databaseMobileActions = mobileActionsMarkup($databaseHeading, 'database-mobile-actions', 'database-mobile-section');
    $serviceMobileActions = mobileActionsMarkup($serviceHeading, 'service-mobile-actions', 'service-mobile-section');

    expect(mobileActionsAreBeforeSelect($applicationHeading, 'application-mobile-actions', 'application-mobile-section'))->toBeTrue();
    expect(mobileActionsAreBeforeSelect($databaseHeading, 'database-mobile-actions', 'database-mobile-section'))->toBeTrue();
    expect(mobileActionsAreBeforeSelect($serviceHeading, 'service-mobile-actions', 'service-mobile-section'))->toBeTrue();

    expect($applicationHeading)
        ->toContain('application-mobile-actions')
        ->toContain("'route' => 'project.application.command'")
        ->toContain("'navigate' => false")
        ->toContain("value.startsWith('location|')")
        ->toContain('window.location.href = url')
        ->toContain('application-mobile-stop-trigger')
        ->toContain('application-mobile-deploy-trigger')
        ->toContain('application-mobile-restart-trigger')
        ->toContain('application-mobile-force-deploy-trigger')
        ->not->toContain('<optgroup label="Actions">');

    expect($applicationMobileActions)
        ->toContain('mb-3')
        ->toContain('Actions')
        ->toContain('<x-forms.button isError class="shrink-0"')
        ->not->toContain('button type="button" class="button shrink-0 text-error"')
        ->toContain('M7 4v16l13 -8z')
        ->toContain('M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747')
        ->toContain('M6 5m0 1a1 1 0 0 1 1 -1h2');

    expect($applicationHeading)
        ->toContain('application-mobile-section-label')
        ->toContain('Section');

    expect($databaseHeading)
        ->toContain('database-mobile-section')
        ->toContain('database-mobile-actions')
        ->toContain('<optgroup label="Database">')
        ->toContain('<optgroup label="Configuration">')
        ->toContain("'route' => 'project.database.command'")
        ->toContain("'navigate' => false")
        ->toContain("value.startsWith('location|')")
        ->toContain('window.location.href = url')
        ->toContain('window.Livewire?.navigate ? window.Livewire.navigate(url) : window.location.href = url')
        ->toContain("window.Livewire?.hook?.('morphed'")
        ->toContain('x-model="selected"')
        ->toContain('database-restart-trigger')
        ->toContain('database-stop-trigger')
        ->toContain('database-start-trigger')
        ->toContain('scrollbar hidden min-h-10')
        ->not->toContain('<optgroup label="Links">')
        ->not->toContain('<optgroup label="Actions">')
        ->not->toContain('@selected');

    expect($databaseMobileActions)
        ->toContain('mb-3')
        ->toContain('Actions')
        ->toContain('<x-forms.button isError class="shrink-0"')
        ->not->toContain('button type="button" class="button shrink-0 text-error"')
        ->toContain('M7 4v16l13 -8z')
        ->toContain('M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747')
        ->toContain('M6 5m0 1a1 1 0 0 1 1 -1h2');

    expect($databaseHeading)
        ->toContain('database-mobile-section-label')
        ->toContain('Section');

    expect($serviceHeading)
        ->toContain('service-mobile-section')
        ->toContain('service-mobile-actions')
        ->toContain('<optgroup label="Service">')
        ->toContain('<optgroup label="Configuration">')
        ->toContain('<optgroup label="Resource">')
        ->toContain('<optgroup label="Links">')
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
        ->toContain('flex flex-nowrap')
        ->toContain('overflow-x-auto')
        ->not->toContain('<optgroup label="Actions">')
        ->not->toContain('order-first flex flex-wrap items-center gap-2 sm:order-last')
        ->not->toContain('@selected');

    expect($serviceMobileActions)
        ->toContain('mb-3')
        ->toContain('Actions')
        ->toContain('<x-forms.button isError class="shrink-0"')
        ->not->toContain('button type="button" class="button shrink-0 text-error"')
        ->toContain('M7 4v16l13 -8z')
        ->toContain('M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747')
        ->toContain('M6 5m0 1a1 1 0 0 1 1 -1h2');

    expect($serviceHeading)
        ->toContain('service-mobile-section-label')
        ->toContain('Section');
});

function mobileActionsMarkup(string $heading, string $actionsId, string $selectId): string
{
    $actionsPosition = strpos($heading, 'id="'.$actionsId.'"');
    $selectPosition = strpos($heading, 'id="'.$selectId.'"');

    if ($actionsPosition === false || $selectPosition === false || $actionsPosition > $selectPosition) {
        return '';
    }

    return substr($heading, $actionsPosition, $selectPosition - $actionsPosition);
}

function mobileActionsAreBeforeSelect(string $heading, string $actionsId, string $selectId): bool
{
    $actionsPosition = strpos($heading, 'id="'.$actionsId.'"');
    $selectPosition = strpos($heading, 'id="'.$selectId.'"');

    return $actionsPosition !== false && $selectPosition !== false && $actionsPosition < $selectPosition;
}

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
