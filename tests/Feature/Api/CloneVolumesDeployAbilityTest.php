<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Project::factory()->create(['team_id' => $this->team->id])->environments()->first();
    $destinationAttributes = [
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ];

    $this->resources = [
        'applications' => Application::factory()->create($destinationAttributes),
        'services' => Service::factory()->create($destinationAttributes + ['server_id' => $server->id]),
        'databases' => StandalonePostgresql::create($destinationAttributes + [
            'name' => 'source-db',
            'image' => 'postgres:17-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
        ]),
    ];

    Queue::fake();
    Bus::fake();
});

function cloneAbilityHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'clone-volumes-ability-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

test('write-only token cannot clone volume data', function (string $type) {
    $resource = $this->resources[$type];
    $countBefore = $resource::query()->count();

    $this->withHeaders(cloneAbilityHeaders($this->user, $this->team->id, ['write']))
        ->postJson("/api/v1/{$type}/{$resource->uuid}/clone", [
            'destination_uuid' => $this->destination->uuid,
            'clone_volumes' => true,
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy. Cloning volume data stops and starts the source resource, so it needs a token with the deploy permission.');

    expect($resource::query()->count())->toBe($countBefore);
})->with(['applications', 'services', 'databases']);

test('write-only token can clone without volume data', function (string $type) {
    $resource = $this->resources[$type];
    $countBefore = $resource::query()->count();

    $this->withHeaders(cloneAbilityHeaders($this->user, $this->team->id, ['write']))
        ->postJson("/api/v1/{$type}/{$resource->uuid}/clone", [
            'destination_uuid' => $this->destination->uuid,
            'clone_volumes' => false,
        ])
        ->assertCreated();

    expect($resource::query()->count())->toBe($countBefore + 1);
})->with(['applications', 'services', 'databases']);

test('write and deploy token passes the volume clone check', function (string $type) {
    $resource = $this->resources[$type];

    // An unknown destination stops the request right after the ability check, before any server work.
    $this->withHeaders(cloneAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->postJson("/api/v1/{$type}/{$resource->uuid}/clone", [
            'destination_uuid' => 'missing-destination',
            'clone_volumes' => true,
        ])
        ->assertNotFound()
        ->assertJsonPath('message', 'Destination not found.');
})->with(['applications', 'services', 'databases']);
