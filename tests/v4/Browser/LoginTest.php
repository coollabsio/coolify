<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

it('shows registration page when no users exist', function () {
    $page = visit('/login');

    $page->assertSee('Root User Setup')
        ->assertSee('Create Account')
        ->screenshot();
});

it('can login with valid credentials', function () {
    User::factory()->create([
        'id' => 0,
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    $page = visit('/login');

    $page->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Welcome to Coolify')
        ->screenshot();
});

it('fails login with invalid credentials', function () {
    User::factory()->create([
        'id' => 0,
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    $page = visit('/login');

    $page->fill('email', 'random@email.com')
        ->fill('password', 'wrongpassword123')
        ->click('Login')
        ->assertSee('These credentials do not match our records')
        ->screenshot();
});
