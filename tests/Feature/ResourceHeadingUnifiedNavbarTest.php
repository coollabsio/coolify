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
    $tabsComponent = file_get_contents(resource_path('views/components/resource-heading-tabs.blade.php'));

    // Links must not sit inside the overflow-x-auto tabs scroller (causes scrollbar on open).
    expect($application)
        ->toContain('<x-resource-heading-tabs')
        ->toContain('resource-heading-menus')
        ->toContain('<x-applications.links');

    // Mobile and desktop each have a tabs + menus pair; verify every pair.
    preg_match_all('/<x-resource-heading-tabs/', $application, $applicationTabsMatches, PREG_OFFSET_CAPTURE);
    preg_match_all('/resource-heading-menus/', $application, $applicationMenusMatches, PREG_OFFSET_CAPTURE);
    expect($applicationTabsMatches[0])->toHaveCount(2);
    expect($applicationMenusMatches[0])->toHaveCount(2);

    foreach ($applicationTabsMatches[0] as $index => [$match, $tabsOffset]) {
        $menusOffset = $applicationMenusMatches[0][$index][1];
        $overflowInTabs = substr($application, $tabsOffset, $menusOffset - $tabsOffset);

        expect($overflowInTabs)->not->toContain('<x-applications.links');
        expect(strpos($application, '<x-applications.links', $tabsOffset))
            ->toBeGreaterThan($menusOffset);
    }

    preg_match_all('/<x-resource-heading-tabs/', $service, $serviceTabsMatches, PREG_OFFSET_CAPTURE);
    preg_match_all('/resource-heading-menus/', $service, $serviceMenusMatches, PREG_OFFSET_CAPTURE);
    expect($serviceTabsMatches[0])->toHaveCount(2);
    expect($serviceMenusMatches[0])->toHaveCount(2);

    foreach ($serviceTabsMatches[0] as $index => [$match, $tabsOffset]) {
        $menusOffset = $serviceMenusMatches[0][$index][1];
        $overflowInTabs = substr($service, $tabsOffset, $menusOffset - $tabsOffset);

        expect($overflowInTabs)->not->toContain('<x-services.links');
        expect(strpos($service, '<x-services.links', $tabsOffset))
            ->toBeGreaterThan($menusOffset);
    }

    expect($css)
        ->toContain('.resource-heading-tabs::-webkit-scrollbar')
        ->toContain('scrollbar-width: none')
        ->toContain('.resource-heading-tabs-control')
        ->toContain('.resource-heading-tabs-control-icon');

    // Kumo-style overflow chevrons live on the shared tabs component.
    expect($tabsComponent)
        ->toContain('Scroll tabs left')
        ->toContain('Scroll tabs right')
        ->toContain('scrollByDir')
        ->toContain('canStart')
        ->toContain('canEnd')
        ->toContain('resource-heading-tabs-control is-start')
        ->toContain('resource-heading-tabs-control is-end')
        ->toContain('scrollActiveIntoView')
        ->toContain('findActiveTab')
        ->toContain('livewire:navigated');
});

it('shows application and service Links on mobile headings', function () {
    $application = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $service = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    $mobileApplicationSection = str($application)->between('class="w-full md:hidden"', 'class="hidden w-full items-center md:flex')->toString();
    $mobileServiceSection = str($service)->between('class="w-full md:hidden"', 'class="hidden w-full items-center md:flex')->toString();

    expect($mobileApplicationSection)
        ->toContain('<x-applications.links')
        ->toContain('resource-heading-menus')
        ->toContain('<x-resource-heading-tabs');

    expect($mobileServiceSection)
        ->toContain('<x-services.links')
        ->toContain('resource-heading-menus')
        ->toContain('<x-resource-heading-tabs');

    // One mobile + one desktop instance.
    expect(substr_count($application, '<x-applications.links'))->toBe(2);
    expect(substr_count($service, '<x-services.links'))->toBe(2);
});

it('uses overflow scroll arrows on resource heading navbars', function () {
    $files = [
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/server/navbar.blade.php'),
        resource_path('views/components/project/navbar.blade.php'),
    ];

    foreach ($files as $path) {
        expect(file_get_contents($path))->toContain('<x-resource-heading-tabs');
    }
});
