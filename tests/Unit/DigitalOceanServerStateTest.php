<?php

use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createDigitalOceanServerForStateTest(string $status = 'active'): Server
{
    $team = Team::create([
        'name' => 'Test Team',
        'personal_team' => false,
    ]);
    $token = CloudProviderToken::create([
        'team_id' => $team->id,
        'provider' => 'digitalocean',
        'token' => 'test-digitalocean-token',
        'name' => 'DigitalOcean',
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => $token->id,
        'digitalocean_droplet_id' => 987,
        'digitalocean_droplet_status' => $status,
    ]);
}

it('marks a DigitalOcean droplet as deleted when the provider returns 404', function () {
    $server = createDigitalOceanServerForStateTest();

    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response(['message' => 'not found'], 404),
    ]);

    expect($server->refreshDigitalOceanState())->toBe('deleted');

    expect($server->fresh()->digitalocean_droplet_status)->toBe('deleted');
});

it('does not mark a DigitalOcean droplet as deleted on transient provider errors', function () {
    $server = createDigitalOceanServerForStateTest();

    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response(['message' => 'server error'], 500),
    ]);

    expect(fn () => $server->refreshDigitalOceanState())
        ->toThrow(Exception::class);

    expect($server->fresh()->digitalocean_droplet_status)->toBe('active');
});
