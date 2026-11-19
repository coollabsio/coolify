<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedBrowserResourceStack();
});

it('falls back to the default theme color when the stored color is not hex', function () {
    loginAndSkipBoarding();

    $page = visit('/')->assertSee('Dashboard');
    $page->script("localStorage.setItem('theme', 'custom'); localStorage.setItem('themeColor', 'purple');");

    $page = $page->navigate('/')->assertSee('Dashboard')->assertNoJavaScriptErrors();

    $result = $page->script(<<<'JS'
        () => ({
            base: document.documentElement.style.getPropertyValue('--theme-base-color'),
            foreground: document.documentElement.style.getPropertyValue('--theme-accent-foreground'),
            stored: localStorage.getItem('themeColor'),
            shorthand: window.hexToOklch('#fff'),
            rgb: window.hexToOklch('rgb(107,22,237)'),
            oklch: window.themeAccentForeground('oklch(50% 0.2 290)'),
        })
    JS);

    $page->screenshot(filename: 'theme-color-fallback');

    expect($result)->toBe([
        'base' => 'oklch(49.65% 0.2709 289.33)',
        'foreground' => 'oklch(100% 0 0)',
        'stored' => '#6b16ed',
        'shorthand' => 'oklch(100.00% 0.0000 89.88)',
        'rgb' => 'oklch(49.65% 0.2709 289.33)',
        'oklch' => 'oklch(100% 0 0)',
    ]);
});
