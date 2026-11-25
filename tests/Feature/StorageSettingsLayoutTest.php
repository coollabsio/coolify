<?php

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => null,
        'public_ipv4' => null,
        'public_ipv6' => null,
    ]);
});

it('renders the danger zone page for a storage destination', function () {
    $storage = S3Storage::create([
        'uuid' => (string) str()->uuid(),
        'name' => 'minio',
        'description' => 'Local MinIO',
        'endpoint' => 'http://minio:9000',
        'bucket' => 'coolify',
        'region' => 'us-east-1',
        'key' => 'access',
        'secret' => 'secret',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);

    $this->get(route('storage.danger', ['storage_uuid' => $storage->uuid]))
        ->assertOk()
        ->assertSee('Danger zone', false)
        ->assertSee('Delete storage', false)
        ->assertSee('Connected', false)
        ->assertDontSee('Validate connection', false);
});
