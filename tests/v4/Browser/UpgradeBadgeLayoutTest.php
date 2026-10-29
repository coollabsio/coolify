<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('keeps the update badge inside the sidebar brand area', function (string $version) {
    config()->set('constants.coolify.version', $version);
    seedBrowserResourceStack(['instanceSettings' => ['new_version_available' => true]]);
    Cache::put('coolify:versions:all', ['coolify' => ['v4' => ['version' => '9.9.9']]], 3600);

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->assertVisible('header button[aria-label="Update available"]');

    $layout = $page->script(<<<'JS'
        () => {
            const badge = document.querySelector('header button[aria-label="Update available"]');
            const brand = badge.closest('header > div');

            return {
                badgeRight: badge.getBoundingClientRect().right,
                brandRight: brand.getBoundingClientRect().right,
                brandOverflows: brand.scrollWidth > brand.clientWidth,
            };
        }
    JS);

    $page->screenshot(filename: 'upgrade-badge-layout');

    expect($layout['brandOverflows'])->toBeFalse()
        ->and($layout['badgeRight'])->toBeLessThanOrEqual($layout['brandRight']);
})->with(['4.3.19', '4.0.0-beta.470']);
