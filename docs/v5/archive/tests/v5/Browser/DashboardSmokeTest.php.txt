<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0, 'is_sponsorship_popup_enabled' => false]);

    $this->user = User::factory()->create([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);
});

it('redirects guests to login', function () {
    $page = visit('/v5');

    $page->assertPathIs('/login')
        ->screenshot();
});

it('shows the dashboard canvas for an authenticated user', function () {
    $this->actingAs($this->user);

    $page = visit('/v5');

    $page->assertSee('Deploy')
        ->assertSee('No applications on this canvas yet.')
        ->assertNoJavaScriptErrors()
        ->screenshot();
});

it('shows the clusters page for an authenticated user', function () {
    $this->actingAs($this->user);

    $page = visit('/v5/clusters');

    $page->assertSee('Clusters')
        ->assertNoJavaScriptErrors()
        ->screenshot();
});
