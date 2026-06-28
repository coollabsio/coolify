<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

it('shows registration page when no users exist', function () {
    $page = visit('/register');

    $page->assertSee('Root User Setup')
        ->assertSee('Create Account')
        ->screenshot();
});

it('can register a new root user', function () {
    $page = visit('/register');

    $page->fill('name', 'Test User')
        ->fill('email', 'root@example.com')
        ->fill('password', 'Password1!@')
        ->fill('password_confirmation', 'Password1!@')
        ->click('Create Account')
        ->assertPathIs('/onboarding')
        ->screenshot();

    expect(User::where('email', 'root@example.com')->exists())->toBeTrue();
});

it('fails registration with mismatched passwords', function () {
    $page = visit('/register');

    $page->fill('name', 'Test User')
        ->fill('email', 'root@example.com')
        ->fill('password', 'Password1!@')
        ->fill('password_confirmation', 'DifferentPass1!@')
        ->click('Create Account')
        ->assertSee('password')
        ->screenshot();
});

it('fails registration with weak password', function () {
    $page = visit('/register');

    $page->fill('name', 'Test User')
        ->fill('email', 'root@example.com')
        ->fill('password', 'short')
        ->fill('password_confirmation', 'short')
        ->click('Create Account')
        ->assertSee('password')
        ->screenshot();
});

it('shows login link when a user already exists', function () {
    User::factory()->create(['id' => 0]);

    $page = visit('/register');

    $page->assertSee('Already registered?')
        ->assertDontSee('Root User Setup')
        ->screenshot();
});
