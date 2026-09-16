<?php

/**
 * The custom theme derives its whole palette from one user-picked color.
 * These tests lock in two guarantees:
 *  1. The pipeline is authored entirely in OKLCH (no hex color literals).
 *  2. Foreground colors (accent text, placeholders) are clamped so they stay
 *     readable on the dark surfaces regardless of the picked hue.
 */
function customThemeBlock(): string
{
    $css = file_get_contents(resource_path('css/app.css'));

    return str($css)
        ->after('html[data-theme="custom"] {')
        ->before('}')
        ->toString();
}

it('derives the custom theme entirely in oklch with no hex color literals', function () {
    expect(customThemeBlock())
        ->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
        ->toContain('--theme-base-color:')
        ->toContain('oklch(');
});

it('adds clamped, readable foreground tokens for the custom theme', function () {
    $block = customThemeBlock();

    expect($block)
        // Accent text/icons on dark surfaces: lightness forced into a readable band.
        ->toContain('--theme-fg-on-surface:')
        ->toMatch('/--theme-fg-on-surface:\s*oklch\(from var\(--theme-base-color\)[^;]*clamp\(/')
        // Placeholder: light, near-neutral, guaranteed readable.
        ->toContain('--theme-placeholder-color:')
        ->toMatch('/--theme-placeholder-color:\s*oklch\(from var\(--theme-base-color\)/');
});

it('routes custom-theme accent text utilities to the readable on-surface token', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/\[class~="text-warning"\][^{]*\{\s*color:\s*var\(--theme-fg-on-surface\)/')
        ->toContain('[class~="text-coollabs"]');
});

it('uses the clamped placeholder token for custom-theme inputs', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/html\[data-theme="custom"\] input::placeholder[^}]*var\(--theme-placeholder-color\)/');
});

it('adds a light custom variant that tints surfaces toward white with dark foreground', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('html[data-theme="custom"]:not(.dark)')
        // Surfaces hold a fixed high lightness with injected base-hue chroma, so the
        // tint is visible (not a washed-out mix into white).
        ->toMatch('/html\[data-theme="custom"\]:not\(\.dark\)[^}]*--color-app:\s*oklch\(from var\(--theme-base-color\) 9[0-9](\.[0-9]+)?% min\(c,/s')
        // Accent text/icons clamp to a dark, readable band on light surfaces.
        ->toMatch('/html\[data-theme="custom"\]:not\(\.dark\)[^}]*--theme-fg-on-surface:\s*oklch\(from var\(--theme-base-color\) clamp\(0\.3/s');
});

it('lets the theme scripts toggle a custom light or dark mode via customMode', function () {
    $base = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $component = file_get_contents(resource_path('views/components/theme-controls.blade.php'));
    $picker = file_get_contents(resource_path('views/components/theme-controls/picker.blade.php'));
    $appearance = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));
    $accountMenu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($base)
        // One shared Alpine factory holds the theme logic.
        ->toContain('window.themeControls')
        ->toContain('localStorage.customMode')
        ->toContain('setCustomMode')
        // The picker no longer force-adds dark; it follows customMode.
        ->not->toContain("document.documentElement.classList.add('dark')")
        // Light/dark lives inside the custom color picker popover.
        ->and($picker)
        ->toContain('customMode')
        ->toContain('setCustomMode')
        ->toContain('type="color"')
        ->and($component)
        // Both variants open the shared picker popover for Custom.
        ->toContain('<x-theme-controls.picker')
        ->toContain('chooseCustom')
        // Both the page and the dropdown reuse the one component.
        ->and($appearance)
        ->toContain('<x-theme-controls variant="full"')
        ->and($accountMenu)
        ->toContain('<x-theme-controls variant="menu"');
});

it('computes the theme base color and accent foreground in oklch, not hex', function () {
    $base = file_get_contents(resource_path('views/layouts/base.blade.php'));

    $accentFn = str($base)
        ->after('window.themeAccentForeground')
        ->before('window.applyStoredTheme')
        ->toString();

    expect($base)
        ->toContain('window.hexToOklch')
        ->and($accentFn)
        ->toContain('oklch(0% 0 0)')
        ->toContain('oklch(100% 0 0)')
        ->not->toContain('#000000')
        ->not->toContain('#ffffff');
});
