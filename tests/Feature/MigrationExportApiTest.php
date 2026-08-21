<?php

use App\Enums\ResourceMigrationStatus;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ResourceMigration;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    config(['app.maintenance.driver' => 'file', 'app.maintenance.store' => 'array']);
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => false,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);
});

function migrationHeaders(?string $token = null): array
{
    return [
        'Authorization' => 'Bearer '.($token ?? test()->bearerToken),
        'Content-Type' => 'application/json',
    ];
}

test('preflight returns version and token abilities', function () {
    $response = $this->withHeaders(migrationHeaders())
        ->getJson('/api/v1/migrations/preflight?server_uuid='.$this->server->uuid);

    $response->assertSuccessful()
        ->assertJsonPath('token_can_write', true)
        ->assertJsonPath('token_can_read_sensitive', true)
        ->assertJsonPath('docker_running', true);
});

test('lists migratable resources for the token team', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'migratable-app',
    ]);

    $response = $this->withHeaders(migrationHeaders())
        ->getJson('/api/v1/migrations/resources');

    $response->assertSuccessful();
    $uuids = collect($response->json())->pluck('uuid');
    expect($uuids)->toContain($application->uuid);
});

test('exports resource metadata and returns a manifest', function () {
    $database = StandalonePostgresql::create([
        'name' => 'export-db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'secret',
        'postgres_db' => 'app',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'export-app',
    ]);

    EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => "postgres://postgres:secret@{$database->uuid}:5432/app",
        'resourceable_id' => $application->id,
        'resourceable_type' => $application->getMorphClass(),
        'is_preview' => false,
    ]);

    $response = $this->withHeaders(migrationHeaders())->postJson('/api/v1/migrations/export', [
        'resource_uuids' => [$database->uuid, $application->uuid],
        'skip_data' => true,
        'storage' => [
            'driver' => 's3',
            'config' => [
                'endpoint' => 'https://s3.example.com',
                'bucket' => 'migrations',
                'key' => 'key',
                'secret' => 'secret',
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', ResourceMigrationStatus::Completed->value);

    $show = $this->withHeaders(migrationHeaders())
        ->getJson('/api/v1/migrations/'.$response->json('uuid'));

    $show->assertSuccessful();
    $types = collect($show->json('manifest.resources'))->pluck('type');
    $envValues = collect($show->json('manifest.resources'))
        ->pluck('environment_variables')
        ->flatten(1)
        ->pluck('value');

    expect($types)->toContain('standalone-postgresql')
        ->and($types)->toContain('application')
        ->and($envValues->implode(' '))->toContain($database->uuid);
});

test('rejects export without the read sensitive ability', function () {
    $token = $this->user->createToken('write-only', ['write'])->plainTextToken;
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $response = $this->withHeaders(migrationHeaders($token))->postJson('/api/v1/migrations/export', [
        'resource_uuids' => [$application->uuid],
        'skip_data' => true,
        'storage' => ['driver' => 's3', 'config' => ['endpoint' => 'https://s3.example.com', 'bucket' => 'b', 'key' => 'k', 'secret' => 's']],
    ]);

    $response->assertForbidden();
});

test('rejects export of another teams resources', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    $foreign = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);

    $response = $this->withHeaders(migrationHeaders())->postJson('/api/v1/migrations/export', [
        'resource_uuids' => [$foreign->uuid],
        'skip_data' => true,
        'storage' => ['driver' => 's3', 'config' => ['endpoint' => 'https://s3.example.com', 'bucket' => 'b', 'key' => 'k', 'secret' => 's']],
    ]);

    $response->assertNotFound();
    expect(ResourceMigration::count())->toBe(0);
});
