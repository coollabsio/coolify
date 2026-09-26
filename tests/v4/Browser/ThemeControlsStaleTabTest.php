<?php

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Host-side browser runs have no phpredis; keep maintenance checks off Redis.
    config()->set('app.maintenance.store', 'array');
    seedBrowserInstanceSettings();
    createBrowserRootUser();
});

/**
 * The custom theme color field is always mounted and only hidden with x-show, so
 * it is a target for browser form-state restoration, autofill passes, and
 * extension content scripts on stale or backgrounded tabs. A closed picker is
 * never a color choice, and the theme is persisted in localStorage only.
 */
it('ignores color input events that arrive while the custom picker is closed', function () {
    $page = loginForStaleThemeTabTest();

    seedPlainDarkThemeState($page);
    $page->navigate('/profile/appearance');

    waitForThemeControlsReady($page);

    expect($page->script('localStorage.theme'))->toBe('dark');
    expect(themeControlsPickerCount($page))->toBeGreaterThan(0);

    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('input[type=color]')).forEach((input) => {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    JS);

    expect($page->script('localStorage.theme'))->toBe('dark');
    expect($page->script("localStorage.getItem('themeColor')"))->toBeNull();
    expect($page->script('document.documentElement.dataset.theme'))->toBe('dark');

    $page->assertNoJavaScriptErrors()
        ->screenshot(filename: 'theme-controls-ignore-events-while-picker-closed');
});

it('still applies a color picked through the open custom picker', function () {
    $page = loginForStaleThemeTabTest();

    seedPlainDarkThemeState($page);
    $page->navigate('/profile/appearance');

    waitForThemeControlsReady($page);

    // Opening the Custom card switches to the custom theme and reveals the picker.
    $page->click('div[role=button][aria-haspopup=dialog]')
        ->assertScript("document.querySelector('div[role=button][aria-haspopup=dialog]').getAttribute('aria-expanded') === 'true'");

    $picked = $page->script(<<<'JS'
        (() => {
            const input = Array.from(document.querySelectorAll('input[type=color]')).find((field) => {
                const panel = field.closest('div[role=dialog]');

                return panel && getComputedStyle(panel).display !== 'none';
            });

            if (!input) {
                return false;
            }

            input.value = '#123456';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));

            return true;
        })()
    JS);

    expect($picked)->toBeTrue();
    expect($page->script('localStorage.theme'))->toBe('custom');
    expect($page->script('localStorage.themeColor'))->toBe('#123456');

    $page->assertNoJavaScriptErrors()
        ->screenshot(filename: 'theme-controls-open-picker-color-persisted');
});

/**
 * Log in and finish boarding so the authenticated Appearance page renders.
 */
function loginForStaleThemeTabTest(): mixed
{
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Welcome to Coolify');

    Team::query()->update(['show_boarding' => false]);
    Cache::flush();

    return $page;
}

/**
 * Reset the browser-local theme state so each case starts from a plain dark theme.
 */
function seedPlainDarkThemeState(mixed $page): void
{
    $page->script(<<<'JS'
        localStorage.setItem('theme', 'dark');
        localStorage.removeItem('themeColor');
        localStorage.setItem('customMode', 'dark');
    JS);
}

/**
 * Wait until Alpine has initialized the theme controls: the always-mounted color
 * field only exposes the shared themeColor default once its binding is live, and
 * dispatching events earlier would make the regression case pass vacuously.
 */
function waitForThemeControlsReady(mixed $page): void
{
    expect($page->script("document.querySelectorAll('div[role=button][aria-haspopup=dialog]').length"))->toBe(1);

    $page->assertScript(<<<'JS'
        (() => {
            const card = document.querySelector('div[role=button][aria-haspopup=dialog]');

            return card?.parentElement?.querySelector('input[type=color]')?.value ?? null;
        })()
    JS, '#6b16ed');
}

/**
 * Count the always-mounted color fields on the page.
 */
function themeControlsPickerCount(mixed $page): int
{
    return (int) $page->script("document.querySelectorAll('input[type=color]').length");
}
