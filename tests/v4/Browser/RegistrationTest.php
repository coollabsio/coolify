<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
