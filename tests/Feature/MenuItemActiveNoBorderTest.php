<?php

/**
 * Active sidebar/nav items use a solid fill only — no left accent rail
 * (::before) and no gradient wash.
 */
test('active menu items do not render an accent rail', function () {
    $appCss = file_get_contents(resource_path('css/app.css'));
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    preg_match('/@utility menu-item-active \{[^}]*\}/s', $utilities, $menuItemActive);
    preg_match('/@utility menu-subitem-active \{[^}]*\}/s', $utilities, $menuSubitemActive);

    expect($menuItemActive[0] ?? '')
        ->toContain('rounded-md')
        ->toContain('bg-black/[0.05]')
        ->and($menuSubitemActive[0] ?? '')
        ->toContain('rounded-md')
        ->toContain('bg-black/[0.05]');

    // Accent rail must be disabled (content: none), not drawn as a 3px accent bar.
    expect($appCss)
        ->toMatch('/\.menu-item-active::before,\s*\.menu-subitem-active::before\s*\{[^}]*content:\s*none/s')
        ->not->toMatch('/\.menu-item-active::before\s*\{[^}]*width:\s*3px/s')
        ->not->toMatch('/\.menu-item-active::before\s*\{[^}]*background:\s*var\(--color-accent\)/s');
});
