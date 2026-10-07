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

it('suppresses proxy error responses and shows a toast', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Welcome to Coolify');

    // Boarding redirects every other path; finish it so the preview page loads.
    // User::currentTeam() caches the team, so flush after the update.
    Team::query()->update(['show_boarding' => false]);
    Cache::flush();

    $page->navigate('/__livewire-request-failure')
        ->assertSee('Livewire request failure preview')
        ->click('502')
        ->assertSee('Action could not be completed')
        ->assertSee('Coolify did not receive a response. Please try again.')
        ->assertSee('Livewire request failure preview')
        ->assertDontSee('cloudflare proxy error')
        ->screenshot(filename: 'livewire-request-failure-toast');
});

it('shows the preview page without a toast before any failure', function () {
    $page = visit('/__livewire-request-failure');

    $page->assertSee('Livewire request failure preview')
        ->assertDontSee('Action could not be completed')
        ->screenshot(filename: 'livewire-request-failure-initial');

    // layouts.simple must render Livewire's styles, or wire:loading spinners leak.
    $spinnerDisplay = $page->script('getComputedStyle(document.querySelector("[wire\\\\:loading]")).display');
    expect($spinnerDisplay)->toBe('none');
});
