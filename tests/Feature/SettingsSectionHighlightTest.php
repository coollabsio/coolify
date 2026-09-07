<?php

/**
 * Settings nav sub-items scroll a section into view and flash its border
 * for 500ms so the user can see which card was targeted.
 */
test('settings section highlight animation is defined for 500ms', function () {
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($appCss)
        ->toContain('@keyframes application-settings-section-highlight')
        ->toContain('.application-settings-section.is-section-highlight')
        ->toContain('.application-settings-section.is-section-highlight::after')
        ->toContain('animation: application-settings-section-highlight 500ms ease-out forwards')
        ->toContain('border: 0.5px solid var(--color-accent)')
        ->toContain('var(--color-accent)');
});

test('settings navigation leaves room for the default tab focus ring', function () {
    $appCss = file_get_contents(resource_path('css/app.css'));

    preg_match('/\.application-settings-navigation\s*\{[^}]*\}/s', $appCss, $nav);

    // Tab focus keeps the global ring-2 + ring-offset-2; do not thin it.
    expect($appCss)
        ->not->toContain('.menu-item:focus-visible')
        ->not->toContain('box-shadow: inset 0 0 0 0.5px var(--color-accent)')
        ->and($nav[0] ?? '')
        ->toContain('padding-right: 0.375rem');
});

test('configuration sidebar subitems trigger section highlight on scroll', function () {
    $blade = file_get_contents(resource_path('views/livewire/project/application/configuration.blade.php'));
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($blade)
        ->toContain('scrollToSection(id)')
        ->toContain('window.scrollToSettingsSection?.(id)')
        ->toContain("scrollToSection('{{ \$section['id'] }}')")
        ->and($appJs)
        ->toContain('window.scrollToSettingsSection')
        ->toContain("el.classList.add('is-section-highlight')")
        ->toContain("behavior: 'smooth'")
        ->toContain("addEventListener('scrollend'")
        ->toContain('stableFrames');
});

test('postgresql general navigation lists each in-page settings section', function () {
    $sidebar = file_get_contents(resource_path('views/components/database/configuration-sidebar.blade.php'));
    $general = file_get_contents(resource_path('views/livewire/project/database/postgresql/general.blade.php'));

    $sections = [
        'database-details-section' => 'Database details',
        'credentials-section' => 'Credentials',
        'initialization-section' => 'Initialization',
        'runtime-network-section' => 'Runtime and network',
        'public-access-section' => 'Public access',
        'configuration-section' => 'Configuration',
        'log-delivery-section' => 'Log delivery',
        'initialization-scripts-section' => 'Initialization scripts',
    ];

    foreach ($sections as $id => $label) {
        expect($sidebar)
            ->toContain("['id' => '{$id}', 'label' => '{$label}']")
            ->and($general)->toContain("id=\"{$id}\"");
    }

    expect($sidebar)
        ->toContain("\$database->type() === 'standalone-postgresql'")
        ->toContain('window.scrollToSettingsSection?.(id)')
        ->toContain("activeSection === '{{ \$section['id'] }}'")
        ->toContain("scrollToSection('{{ \$section['id'] }}')");
});
