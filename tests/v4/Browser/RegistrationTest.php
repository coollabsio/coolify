<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedBrowserInstanceSettings();
});

it('shows registration page when no users exist', function () {
    $page = visit('/register');

    $page->assertSee('Create the root account for this instance.')
        ->assertSee('Full instance access')
        ->assertSee('Create account')
        ->screenshot(filename: 'registration-root-setup');
});

it('shows how to open the setup window again when it is closed', function () {
    Cache::flush();

    $page = visit('/register');

    $page->assertSee('Setup window closed')
        ->assertSee('docker restart coolify')
        ->assertDontSee('Create account')
        ->screenshot(filename: 'registration-root-setup-window-closed');
});

it('can register a new root user', function () {
    $page = visit('/register');

    $page->fill('name', 'Test User')
        ->fill('email', 'root@example.com')
        ->fill('password', 'Password1!@')
        ->fill('password_confirmation', 'Password1!@')
        ->click('Create account')
        ->assertPathIs('/onboarding')
        ->screenshot(filename: 'registration-success-onboarding');

    expect(User::where('email', 'root@example.com')->exists())->toBeTrue();
});

it('fails registration with mismatched passwords', function () {
    $page = visit('/register');

    $page->fill('name', 'Test User')
        ->fill('email', 'root@example.com')
        ->fill('password', 'Password1!@')
        ->fill('password_confirmation', 'DifferentPass1!@')
        ->click('Create account')
        ->assertSee('password')
        ->screenshot(filename: 'registration-password-mismatch');
});

it('fails registration with weak password', function () {
    $page = visit('/register');

    $page->fill('name', 'Test User')
        ->fill('email', 'root@example.com')
        ->fill('password', 'short')
        ->fill('password_confirmation', 'short')
        ->click('Create account')
        ->assertSee('password')
        ->screenshot(filename: 'registration-weak-password');
});

it('shows login link when a user already exists', function () {
    createBrowserRootUser();

    $page = visit('/register');

    $page->assertSee('Already have an account?')
        ->assertDontSee('Full instance access')
        ->screenshot(filename: 'registration-existing-user');
});

it('keeps the auth card heading visible on short viewports', function (int $width, int $height) {
    $page = visit('/register')->resize($width, $height);

    $page->assertSee('Create account');

    $headingTop = $page->script('document.querySelector(".auth-card-heading h1").getBoundingClientRect().top');
    $submitBottom = $page->script('document.querySelector("button[type=submit]").getBoundingClientRect().bottom');
    $scrollHeight = $page->script('document.documentElement.scrollHeight');

    expect($headingTop)->toBeGreaterThanOrEqual(0)
        ->and($scrollHeight)->toBeGreaterThanOrEqual($submitBottom);

    $page->screenshot(filename: "registration-short-viewport-{$width}x{$height}");
})->with([
    'issue 11100 viewport' => [1422, 612],
    'short laptop viewport' => [1280, 480],
]);
