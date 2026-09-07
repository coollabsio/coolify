<?php

/**
 * Inactive sidebar/nav menu items previously used dark:text-fg-faint (#6e6e74)
 * on near-black app chrome (~3.9:1), below WCAG AA for normal text. Defaults
 * should use fg-dim / neutral-600 instead.
 */
test('inactive menu items use accessible contrast tokens', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    preg_match('/@utility menu-item \{[^}]*\}/s', $utilities, $menuItem);
    preg_match('/@utility menu-subitem \{[^}]*\}/s', $utilities, $menuSubitem);
    preg_match('/@utility sub-menu-item \{[^}]*\}/s', $utilities, $subMenuItem);
    preg_match('/@utility nav-section \{[^}]*\}/s', $utilities, $navSection);

    expect($menuItem[0] ?? '')
        ->toContain('dark:text-fg-dim')
        ->toContain('text-neutral-600')
        ->not->toContain('dark:text-fg-faint')
        ->and($menuSubitem[0] ?? '')
        ->toContain('dark:text-fg-dim')
        ->toContain('text-neutral-600')
        ->not->toContain('dark:text-fg-faint')
        ->and($subMenuItem[0] ?? '')
        ->toContain('dark:text-fg-dim')
        ->toContain('text-neutral-600')
        ->and($navSection[0] ?? '')
        ->toContain('dark:text-fg-dim')
        ->not->toContain('dark:text-fg-faint');
});
