<?php

/**
 * Resource layer-2 navs use one unified pill bar: menus on the left, actions on the right.
 */
it('uses a single unified navbar for application, service, database, and server headings', function () {
    $files = [
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/server/navbar.blade.php'),
    ];

    foreach ($files as $path) {
        $contents = file_get_contents($path);

        expect($contents)
            ->toContain('resource-heading-navbar')
            ->toContain('justify-between')
            ->toContain('border-l border-neutral-200 pl-1');
    }
});

it('keeps links with the menu group and actions on the right for applications and services', function () {
    $application = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    $linksBeforeActionsDivider = function (string $contents, string $linksComponent): bool {
        $linksPos = strpos($contents, $linksComponent);
        $dividerPos = strpos($contents, 'border-l border-neutral-200 pl-1');

        return $linksPos !== false && $dividerPos !== false && $linksPos < $dividerPos;
    };

    expect($linksBeforeActionsDivider($application, '<x-applications.links'))->toBeTrue();
    expect($linksBeforeActionsDivider($service, '<x-services.links'))->toBeTrue();

    // Desktop layer uses exactly one unified pill bar (mobile section may still use its own pill).
    expect(substr_count($application, 'resource-heading-navbar'))->toBe(1);
    expect(substr_count($service, 'resource-heading-navbar'))->toBe(1);
});

it('keeps Links dropdowns outside the scrollable tabs strip', function () {
    $application = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    // Links must not sit inside the overflow-x-auto tabs scroller (causes scrollbar on open).
    expect($application)
        ->toContain('resource-heading-tabs')
        ->toContain('resource-heading-menus')
        ->toContain('<x-applications.links');

    $tabsBlockEnd = strpos($application, 'resource-heading-menus');
    $overflowInTabs = substr(
        $application,
        strpos($application, 'resource-heading-tabs'),
        $tabsBlockEnd - strpos($application, 'resource-heading-tabs'),
    );

    expect($overflowInTabs)->not->toContain('<x-applications.links');
    expect(strpos($application, '<x-applications.links'))->toBeGreaterThan($tabsBlockEnd);

    $serviceTabsEnd = strpos($service, 'resource-heading-menus');
    $serviceOverflow = substr(
        $service,
        strpos($service, 'resource-heading-tabs'),
        $serviceTabsEnd - strpos($service, 'resource-heading-tabs'),
    );

    expect($serviceOverflow)->not->toContain('<x-services.links');
    expect(strpos($service, '<x-services.links'))->toBeGreaterThan($serviceTabsEnd);

    expect($css)
        ->toContain('.resource-heading-tabs::-webkit-scrollbar')
        ->toContain('scrollbar-width: none');
});
