<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedBrowserResourceStack();
});

function customThemeState(mixed $page): array
{
    return $page->script(<<<'JS'
        () => ({
            theme: localStorage.getItem('theme'),
            code: localStorage.getItem('customTheme'),
            themeColor: localStorage.getItem('themeColor'),
            dataTheme: document.documentElement.dataset.theme,
            dark: document.documentElement.classList.contains('dark'),
            sidebarScheme: document.documentElement.dataset.sidebarScheme ?? null,
            background: document.documentElement.style.getPropertyValue('--theme-bg'),
        })
    JS);
}

it('saves a preset from the custom theme dialog and keeps it after a reload', function () {
    loginAndSkipBoarding();

    $page = visit('/profile/appearance')->assertSee('Color theme');
    $page->click('Custom')->assertSee('Presets');
    $page->click('Aubergine')->click('Save theme');

    $expected = [
        'theme' => 'custom',
        'code' => '#3f0e40,#1164a3,#1164a3,#ffffff',
        'themeColor' => '#1164a3',
        'dataTheme' => 'custom',
        'dark' => false,
        'sidebarScheme' => 'dark',
        'background' => '#ffffff',
    ];
    expect(customThemeState($page))->toBe($expected);

    $page = $page->navigate('/')->assertSee('Dashboard')->assertNoJavaScriptErrors();
    $page->screenshot(filename: 'custom-theme-aubergine');

    expect(customThemeState($page))->toBe($expected);
});

it('reads a pasted share code and rejects an invalid one', function () {
    loginAndSkipBoarding();

    $page = visit('/profile/appearance')->assertSee('Color theme');
    $page->click('Custom')->assertSee('Share code');

    $page->type('#custom-theme-share-code', '#123,#456')
        ->assertSee('Enter four hex colors, separated by commas.');

    $page->script(<<<'JS'
        () => {
            const input = document.getElementById('custom-theme-share-code');
            input.value = '#0f172a, #2e4a7d, #f2458f, #1a2338';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    JS);
    $page->assertDontSee('Enter four hex colors, separated by commas.')->click('Save theme');

    expect(customThemeState($page))->toMatchArray([
        'code' => '#0f172a,#2e4a7d,#f2458f,#1a2338',
        'dark' => true,
        'sidebarScheme' => null,
    ]);
});

it('converts an older one-color custom theme to four colors', function () {
    loginAndSkipBoarding();

    $page = visit('/')->assertSee('Dashboard');
    $page->script(<<<'JS'
        () => {
            localStorage.removeItem('customTheme');
            localStorage.setItem('theme', 'custom');
            localStorage.setItem('themeColor', '#16a34a');
            localStorage.setItem('customMode', 'light');
        }
    JS);

    $page = $page->navigate('/')->assertSee('Dashboard')->assertNoJavaScriptErrors();

    expect(customThemeState($page))->toMatchArray([
        'dataTheme' => 'custom',
        'dark' => false,
        'background' => '#f3f7f5',
    ])
        ->and($page->script('() => window.serializeCustomTheme(window.storedCustomTheme())'))
        ->toBe('#e7efeb,#daf0e2,#16a34a,#f3f7f5');
});

it('switches back to the built-in Dark theme with the Coolify preset', function () {
    loginAndSkipBoarding();

    $page = visit('/profile/appearance')->assertSee('Color theme');
    $page->script(<<<'JS'
        () => {
            localStorage.setItem('customTheme', '#0f172a,#2e4a7d,#f2458f,#1a2338');
            localStorage.setItem('theme', 'custom');
        }
    JS);

    $page = $page->navigate('/profile/appearance')->assertSee('Color theme');
    $page->click('Custom')->assertSee('Presets');
    $page->click('Coolify')->click('Save theme');

    expect(customThemeState($page))->toMatchArray([
        'theme' => 'dark',
        'code' => null,
        'dataTheme' => 'dark',
        'dark' => true,
        'sidebarScheme' => null,
    ]);
});
