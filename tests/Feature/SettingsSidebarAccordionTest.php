<?php

// Each grouped resource settings sidebar wires up the shared accordion. Every group
// is expanded by default; a group the user collapsed stays collapsed (client-side,
// via localStorage), except the group containing the active page.

$groupedSidebars = [
    'application' => 'resources/views/components/application/configuration-sidebar.blade.php',
    'database' => 'resources/views/components/database/configuration-sidebar.blade.php',
    'service' => 'resources/views/components/service/configuration-sidebar.blade.php',
    'service-page' => 'resources/views/livewire/project/service/configuration.blade.php',
    'server' => 'resources/views/components/server/sidebar.blade.php',
];

it('wires the collapsible accordion into every grouped settings sidebar', function (string $file) {
    $contents = file_get_contents(base_path($file));

    expect($contents)
        ->toContain('settingsSidebarAccordion(')          // shared Alpine data provider
        ->toContain('$activeGroup')                        // the active group is always open
        ->toContain('nav-section-toggle')                  // group header is a toggle button
        ->toContain('toggle(')                             // header collapses/expands the group
        ->toContain("? 'xl:block' : 'xl:hidden'");         // desktop-only collapse wrapper
})->with($groupedSidebars);

it('only expands in-page sub-sections for the active page', function () {
    // Regression: the application sidebar used to render every item's sub-sections
    // (Advanced's Build/Container/… showed while you were on General).
    $contents = file_get_contents(base_path('resources/views/components/application/configuration-sidebar.blade.php'));

    expect($contents)
        ->toContain("\$menuItem['active'] && filled(\$sections)")
        ->not->toContain('@if (filled($sections))');
});

it('adds a client-side search to the application settings sidebar', function () {
    $sidebar = file_get_contents(base_path('resources/views/components/application/configuration-sidebar.blade.php'));

    expect($sidebar)
        ->toContain('Filter settings')
        ->toContain('x-model.debounce.100ms="search"')
        ->toContain('matches(')
        // Results are built from a flat index that also covers in-page sub-sections,
        // each carrying a breadcrumb (category + parent page).
        ->toContain('$searchIndex')
        ->toContain("'breadcrumb'")
        ->toContain('$pageSections[$item[\'route\']]');

    expect(file_get_contents(base_path('resources/js/settings-sidebar-accordion.js')))
        ->toContain('matches(label)')
        ->toContain('hasResults');
});

it('registers the accordion Alpine provider', function () {
    expect(file_get_contents(base_path('resources/js/app.js')))
        ->toContain('initializeSettingsSidebarAccordionComponent');

    expect(file_get_contents(base_path('resources/js/settings-sidebar-accordion.js')))
        ->toContain("Alpine.data('settingsSidebarAccordion'");
});

it('keeps the group for the active page open', function () {
    $accordion = file_get_contents(base_path('resources/js/settings-sidebar-accordion.js'));

    expect($accordion)
        ->toContain('if (group === this.activeGroup)')
        ->toContain('return true;');
});

it('expands every group by default when the user has not collapsed it', function () {
    $accordion = file_get_contents(base_path('resources/js/settings-sidebar-accordion.js'));

    $isOpen = str($accordion)->after('isOpen(group) {')->before('toggle(group)')->toString();

    expect($isOpen)
        ->toContain('return this.groups[group];')
        ->not->toContain('return false;');
});
