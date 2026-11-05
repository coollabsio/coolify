<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
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

uses(RefreshDatabase::class);

const UI_REJECTED_DESCRIPTION = '{[ > : < ]}';
const UI_ACCEPTED_DESCRIPTION = 'Main API (prod) - v2.0, owner "ops" & admin@example.com! Ünïcode 日本';

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'app.maintenance.driver' => 'file',
        'constants.ssh.mux_enabled' => false,
    ]);
    Queue::fake();
    Bus::fake();

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $token = $this->user->createToken('write-token', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id, 'description' => 'original']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'description' => 'original',
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

/**
 * @return array{0: string, 1: Application|Service|StandalonePostgresql|Server|PrivateKey}
 */
function describedResource(object $test, string $type): array
{
    $destination = [
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'description' => 'original',
    ];

    return match ($type) {
        'application' => ['applications', Application::factory()->create($destination)],
        'service' => ['services', Service::factory()->create([
            ...$destination,
            'server_id' => $test->server->id,
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        ])],
        'database' => ['databases', StandalonePostgresql::create([
            ...$destination,
            'name' => 'test-postgres',
            'image' => 'postgres:15-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
        ])],
        'server' => ['servers', $test->server],
        'private key' => ['security/keys', $test->privateKey],
    };
}

$resourceTypes = ['application', 'service', 'database', 'server', 'private key'];

test('updating a resource rejects a description that the UI rejects', function (string $type) {
    [$path, $resource] = describedResource($this, $type);

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/{$path}/{$resource->uuid}", ['description' => UI_REJECTED_DESCRIPTION])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('description');

    expect($resource->fresh()->description)->toBe('original');
})->with($resourceTypes);

test('updating a resource accepts a description that the UI accepts', function (string $type) {
    [$path, $resource] = describedResource($this, $type);

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/{$path}/{$resource->uuid}", ['description' => UI_ACCEPTED_DESCRIPTION])
        ->assertSuccessful();

    expect($resource->fresh()->description)->toBe(UI_ACCEPTED_DESCRIPTION);
})->with($resourceTypes);

test('updating a resource rejects a name that the UI rejects', function (string $type, mixed $name) {
    [$path, $resource] = describedResource($this, $type);
    $originalName = $resource->name;

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/{$path}/{$resource->uuid}", ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect($resource->fresh()->name)->toBe($originalName);
})->with($resourceTypes)->with([
    'disallowed characters' => '{[ > < ]}',
    'too short' => 'ab',
    'null' => null,
]);

test('updating a resource accepts a name that the UI accepts', function (string $type) {
    [$path, $resource] = describedResource($this, $type);
    $name = 'owner/repo:main (prod) #2 & co, ünï @ v1.0+';

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/{$path}/{$resource->uuid}", ['name' => $name])
        ->assertSuccessful();

    expect($resource->fresh()->name)->toBe($name);
})->with($resourceTypes);

test('creating a private key rejects a description that the UI rejects', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/security/keys', [
            'private_key' => generateSSHKey('ed25519')['private'],
            'description' => UI_REJECTED_DESCRIPTION,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('description');

    expect(PrivateKey::where('description', UI_REJECTED_DESCRIPTION)->exists())->toBeFalse();
});

test('creating a postgresql database rejects a description that the UI rejects', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/databases/postgresql', [
            'server_uuid' => $this->server->uuid,
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'description' => UI_REJECTED_DESCRIPTION,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('description');

    expect(StandalonePostgresql::where('description', UI_REJECTED_DESCRIPTION)->exists())->toBeFalse();
});
