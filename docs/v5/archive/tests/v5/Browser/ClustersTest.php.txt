<?php

use App\Models\InstanceSettings;
use App\Models\User;
use App\Models\V5\Cluster as V5Cluster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    InstanceSettings::create(['id' => 0, 'is_sponsorship_popup_enabled' => false]);

    $this->user = User::factory()->create([
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);
    $this->team = $this->user->teams()->firstOrFail();
});

it('lists existing clusters', function () {
    V5Cluster::query()->create([
        'team_id' => $this->team->id,
        'created_by_user_id' => $this->user->id,
        'name' => 'Existing Mesh',
    ]);

    $this->actingAs($this->user);

    $page = visit('/v5/clusters');

    $page->assertSee('Clusters')
        ->assertSee('Existing Mesh')
        ->assertNoJavaScriptErrors()
        ->screenshot();
});

it('creates a cluster from the clusters page', function () {
    $this->actingAs($this->user);

    $page = visit('/v5/clusters');

    $page->assertSee('Clusters')
        ->click('button[aria-label="Create cluster"]')
        ->assertSee('Create cluster')
        ->fill('[placeholder="Production Mesh"]', 'Test Mesh')
        ->click('button:has-text("Create cluster")')
        ->assertSee('Test Mesh')
        ->assertNoJavaScriptErrors()
        ->screenshot();

    $cluster = V5Cluster::query()->sole();

    expect($cluster->name)->toBe('Test Mesh')
        ->and($cluster->team_id)->toBe($this->team->id);
});

it('shows a validation error when the cluster name is missing', function () {
    $this->actingAs($this->user);

    $page = visit('/v5/clusters');

    $page->click('button[aria-label="Create cluster"]')
        ->click('button:has-text("Create cluster")')
        ->assertSee('The name field is required.')
        ->screenshot();

    expect(V5Cluster::query()->count())->toBe(0);
});
