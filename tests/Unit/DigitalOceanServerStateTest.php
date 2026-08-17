<?php

use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createDigitalOceanServerForStateTest(string $status = 'active', array $attributes = []): Server
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

    return Server::factory()->create(array_merge([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => $token->id,
        'digitalocean_droplet_id' => 987,
        'digitalocean_droplet_status' => $status,
    ], $attributes));
}

it('marks a DigitalOcean droplet as deleted when the provider returns 404', function () {
    $server = createDigitalOceanServerForStateTest();

    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response(['message' => 'not found'], 404),
    ]);

    expect($server->refreshDigitalOceanState())->toBe('deleted');

    expect($server->fresh()->digitalocean_droplet_status)->toBe('deleted');
});

it('backfills a placeholder IP from the DigitalOcean droplet state', function () {
    $server = createDigitalOceanServerForStateTest('new', ['ip' => Server::PLACEHOLDER_IP]);

    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response([
            'droplet' => [
                'id' => 987,
                'status' => 'active',
                'networks' => [
                    'v4' => [
                        ['type' => 'public', 'ip_address' => '203.0.113.10'],
                    ],
                ],
            ],
        ], 200),
    ]);

    expect($server->refreshDigitalOceanState())->toBe('active');
    expect($server->fresh()->ip)->toBe('203.0.113.10');
});

it('does not overwrite an administrator configured address from the DigitalOcean droplet state', function (string $configuredAddress) {
    $server = createDigitalOceanServerForStateTest('active', ['ip' => $configuredAddress]);

    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response([
            'droplet' => [
                'id' => 987,
                'status' => 'active',
                'networks' => [
                    'v4' => [
                        ['type' => 'public', 'ip_address' => '203.0.113.10'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $server->refreshDigitalOceanState();
    expect($server->fresh()->ip)->toBe($configuredAddress);
})->with([
    'public IPv4' => '198.51.100.20',
    'private IPv4' => '10.10.0.12',
    'IPv6' => '2001:db8::10',
    'tunnel hostname' => 'server.internal.example.com',
]);

it('does not overwrite an address configured while DigitalOcean state is loading', function () {
    $server = createDigitalOceanServerForStateTest('new', ['ip' => Server::PLACEHOLDER_IP]);

    Http::fake(function () use ($server) {
        Server::query()->findOrFail($server->id)->update([
            'ip' => 'server.internal.example.com',
        ]);

        return Http::response([
            'droplet' => [
                'id' => 987,
                'status' => 'active',
                'networks' => [
                    'v4' => [
                        ['type' => 'public', 'ip_address' => '203.0.113.10'],
                    ],
                ],
            ],
        ], 200);
    });

    expect($server->refreshDigitalOceanState())->toBe('active')
        ->and($server->fresh()->ip)->toBe('server.internal.example.com')
        ->and($server->digitalocean_droplet_status)->toBe('active');
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
