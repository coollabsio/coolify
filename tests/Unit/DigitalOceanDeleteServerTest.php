<?php

use App\Actions\Server\DeleteServer;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('deletes the DigitalOcean droplet when requested', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response(null, 204),
    ]);

    $team = Team::create([
        'name' => 'Test Team',
        'personal_team' => false,
    ]);
    $token = CloudProviderToken::factory()->create([
        'team_id' => $team->id,
        'provider' => 'digitalocean',
        'token' => 'test-digitalocean-token',
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => $token->id,
        'digitalocean_droplet_id' => 987,
    ]);

    DeleteServer::run(
        serverId: $server->id,
        deleteFromDigitalOcean: true,
        digitalOceanDropletId: 987,
        cloudProviderTokenId: $token->id,
        teamId: $team->id,
    );

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.digitalocean.com/v2/droplets/987');
});

it('retains the server and surfaces a DigitalOcean deletion failure', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response([
            'message' => 'deletion failed',
        ], 500),
    ]);

    $team = Team::factory()->create();
    $token = CloudProviderToken::factory()->create([
        'team_id' => $team->id,
        'provider' => 'digitalocean',
        'token' => 'test-digitalocean-token',
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => $token->id,
        'digitalocean_droplet_id' => 987,
    ]);
    $server->delete();

    expect(fn () => DeleteServer::run(
        serverId: $server->id,
        deleteFromDigitalOcean: true,
        digitalOceanDropletId: 987,
        cloudProviderTokenId: $token->id,
        teamId: $team->id,
    ))->toThrow(RequestException::class, 'status code 500');

    expect(Server::withTrashed()->find($server->id))->not->toBeNull();
});

it('retains the server when no DigitalOcean token can delete the droplet', function () {
    Http::preventStrayRequests();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => null,
        'digitalocean_droplet_id' => 987,
    ]);
    $server->delete();

    expect(fn () => DeleteServer::run(
        serverId: $server->id,
        deleteFromDigitalOcean: true,
        digitalOceanDropletId: 987,
        teamId: $team->id,
    ))->toThrow(RuntimeException::class, 'No DigitalOcean token found');

    expect(Server::withTrashed()->find($server->id))->not->toBeNull();
});
