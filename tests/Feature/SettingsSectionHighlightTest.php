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
