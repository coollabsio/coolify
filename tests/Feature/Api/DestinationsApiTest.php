<?php

use App\Actions\Destination\RemoveStandaloneDockerNetwork;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = destinationsApiToken($this->user, $this->team, ['*']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
});

function destinationsApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function destinationsApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'destinations-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

describe('GET /api/v1/destinations', function () {
    test('lists only destinations owned by the token team', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();

        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->getJson('/api/v1/destinations');

        $response->assertOk();
        $uuids = collect($response->json())->pluck('uuid');

        expect($response->json('0'))->not->toHaveKey('id')
            ->and($uuids)->toContain($this->destination->uuid)
            ->not->toContain($otherDestination->uuid);
    });
});

describe('GET /api/v1/destinations/{uuid}', function () {
    test('does not expose another team destination', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();

        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->getJson("/api/v1/destinations/{$otherDestination->uuid}");

        $response->assertNotFound();
    });
});

describe('GET /api/v1/servers/{server_uuid}/destinations', function () {
    test('lists destinations for a team server', function () {
        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/destinations");

        $response->assertOk();
        expect($response->json())->toHaveCount(1)
            ->and($response->json('0.uuid'))->toBe($this->destination->uuid);
    });
});

describe('POST /api/v1/servers/{server_uuid}/destinations', function () {
    test('creates a standalone destination', function () {
        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'name' => 'API Standalone',
                'network' => 'api-standalone-network',
            ]);

        $response->assertCreated()
            ->assertJson([
                'name' => 'API Standalone',
                'network' => 'api-standalone-network',
                'type' => 'standalone',
                'server_uuid' => $this->server->uuid,
            ]);

        expect(StandaloneDocker::where('server_id', $this->server->id)
            ->where('network', 'api-standalone-network')
            ->exists())->toBeTrue();
    });

    test('requires a write token', function () {
        $readOnlyToken = destinationsApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders(destinationsApiHeaders($readOnlyToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'network' => 'new-network',
            ]);

        $response->assertForbidden();
    });

    test('rejects create requests from non-admin team members', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $memberToken = destinationsApiToken($member, $this->team, ['*']);

        $response = $this->withHeaders(destinationsApiHeaders($memberToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'network' => 'member-network',
            ]);

        $response->assertForbidden();
        expect(StandaloneDocker::where('server_id', $this->server->id)->where('network', 'member-network')->exists())->toBeFalse();
    });

    test('rejects non-json requests before creating a destination', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->post("/api/v1/servers/{$this->server->uuid}/destinations", [
            'network' => 'api-swarm-network',
            'type' => 'swarm',
        ]);

        $response->assertStatus(400);
        expect(SwarmDocker::where('server_id', $this->server->id)->where('network', 'api-swarm-network')->exists())->toBeFalse();
    });

    test('rejects unknown fields', function () {
        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'network' => 'new-network',
                'unexpected' => 'value',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['unexpected']);
    });

    test('rejects unsafe docker network names', function () {
        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'network' => 'bad;network',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['network']);
    });

    test('rejects a destination type that does not match the server mode', function () {
        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'network' => 'wrong-type-network',
                'type' => 'swarm',
            ]);

        $response->assertUnprocessable();
        expect(SwarmDocker::where('server_id', $this->server->id)->where('network', 'wrong-type-network')->exists())->toBeFalse();
    });

    test('creates a swarm destination on a swarm server', function () {
        $this->server->settings()->update(['is_swarm_manager' => true]);

        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'name' => 'API Swarm',
                'network' => 'api-swarm-network',
                'type' => 'swarm',
            ]);

        $response->assertCreated();
        $response->assertJsonStructure(['uuid']);
        expect(SwarmDocker::where('server_id', $this->server->id)->where('network', 'api-swarm-network')->exists())->toBeTrue();
    });

    test('rejects duplicate networks on the same server and type', function () {
        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'network' => $this->destination->network,
            ]);

        $response->assertStatus(409);
    });

    test('returns conflict when the database unique constraint wins a create race', function () {
        $network = 'raced-network';

        StandaloneDocker::creating(function (StandaloneDocker $destination) use ($network) {
            if ($destination->network !== $network) {
                return;
            }

            DB::table('standalone_dockers')->insert([
                'name' => 'Concurrent destination',
                'uuid' => (string) Str::uuid(),
                'network' => $network,
                'server_id' => $destination->server_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", [
                'network' => $network,
            ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'A destination with this network already exists on the server.']);

        expect(StandaloneDocker::where('server_id', $this->server->id)->where('network', $network)->count())->toBe(1);
    });
});

describe('DELETE /api/v1/destinations/{uuid}', function () {
    test('requires a write token', function () {
        $readOnlyToken = destinationsApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders(destinationsApiHeaders($readOnlyToken))
            ->deleteJson("/api/v1/destinations/{$this->destination->uuid}");

        $response->assertForbidden();
        $this->assertModelExists($this->destination);
    });

    test('rejects delete requests from non-admin team members', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $memberToken = destinationsApiToken($member, $this->team, ['*']);

        $response = $this->withHeaders(destinationsApiHeaders($memberToken))
            ->deleteJson("/api/v1/destinations/{$this->destination->uuid}");

        $response->assertForbidden();
        $this->assertModelExists($this->destination);
    });

    test('deletes standalone destinations after removing the docker network', function () {
        $cleanup = Mockery::mock(RemoveStandaloneDockerNetwork::class);
        $cleanup->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (StandaloneDocker $destination) => $destination->is($this->destination)));
        $this->app->instance(RemoveStandaloneDockerNetwork::class, $cleanup);

        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->deleteJson("/api/v1/destinations/{$this->destination->uuid}");

        $response->assertOk();
        $this->assertModelMissing($this->destination);
    });

    test('blocks deleting a destination with an attached service', function () {
        $project = Project::factory()->create(['team_id' => $this->team->id]);
        $environment = $project->environments()->first();

        Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(destinationsApiHeaders($this->bearerToken))
            ->deleteJson("/api/v1/destinations/{$this->destination->uuid}");

        $response->assertStatus(409);
        $this->assertModelExists($this->destination);
    });
});
