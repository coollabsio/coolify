<?php

use App\Actions\Database\StartDatabaseImport;
use App\Http\Middleware\ApiAbility;
use App\Http\Middleware\EnsureTokenBelongsToCurrentTeamMember;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->tokens()->create(['name' => 'imports', 'token' => hash('sha256', 'secret'), 'abilities' => ['deploy', 'read'], 'team_id' => $this->team->id]);
    $this->headers = ['Authorization' => 'Bearer '.$this->token->id.'|secret'];
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::firstOrCreate(['server_id' => $this->server->id, 'network' => 'coolify'], ['uuid' => (string) Str::uuid(), 'name' => 'docker']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('validates standalone import source and hides foreign databases', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);

    $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$database->uuid}/imports", ['source' => 'upload', 'path' => '../bad'])
        ->assertUnprocessable()->assertJsonValidationErrors(['upload_id', 'path']);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $foreign = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'foreign', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $otherEnvironment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);

    $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$foreign->uuid}/imports", ['source' => 'server', 'path' => '/tmp/a.sql'])->assertNotFound();
});

test('requires deploy ability to start standalone import', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
    $read = $this->user->createToken('read', ['read']);

    $this->withToken($read->plainTextToken)->postJson("/api/v1/databases/{$database->uuid}/imports", ['source' => 'server', 'path' => '/tmp/a.sql'])->assertForbidden();
});

test('audits a successfully queued standalone import', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
    $activity = Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]);
    $action = Mockery::mock(StartDatabaseImport::class);
    $action->shouldReceive('handle')->once()->andReturn($activity);
    app()->instance(StartDatabaseImport::class, $action);

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/databases/{$database->uuid}/imports", ['source' => 'server', 'path' => '/tmp/backup.sql'])
        ->assertAccepted();

    $event = AuditEvent::query()->where('event', 'api.database.import_started')->sole();

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->resource_uuid)->toBe($database->uuid)
        ->and($event->resource_name)->toBe($database->name)
        ->and($event->metadata['source'])->toBe('server')
        ->and($event->metadata['activity_id'])->toBe($activity->id);
});

test('passes the replace existing option to a standalone database import', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
    $activity = Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]);
    $action = Mockery::mock(StartDatabaseImport::class);
    $action->shouldReceive('handle')->once()->withArgs(fn ($resource, $source, $teamId) => $resource->is($database)
        && $source->replaceExisting === true
        && $teamId === $this->team->id)->andReturn($activity);
    app()->instance(StartDatabaseImport::class, $action);

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/databases/{$database->uuid}/imports", [
            'source' => 'server',
            'path' => '/tmp/backup.dump',
            'replace_existing' => true,
        ])
        ->assertAccepted();
});

test('validates replace existing as a boolean', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/databases/{$database->uuid}/imports", [
            'source' => 'server',
            'path' => '/tmp/backup.dump',
            'replace_existing' => 'yes',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('replace_existing');
});

test('rejects unknown fields on standalone import', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
    $activity = Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]);
    $action = Mockery::mock(StartDatabaseImport::class);
    $action->shouldReceive('handle')->andReturn($activity);
    app()->instance(StartDatabaseImport::class, $action);

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/databases/{$database->uuid}/imports", [
            'source' => 'server',
            'path' => '/tmp/backup.sql',
            'unknown_field' => 'nope',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unknown_field.0', 'This field is not allowed.');
});

test('returns only a team and resource scoped import activity', function () {
    $this->token->update(['abilities' => ['read', 'read:sensitive']]);
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
    $activity = Activity::create(['log_name' => 'default', 'description' => json_encode([['order' => 1, 'output' => 'restored', 'type' => 'stdout']]), 'properties' => ['team_id' => $this->team->id, 'type_uuid' => $database->uuid, 'operation' => 'database_import', 'status' => 'finished', 'exitCode' => 0]]);

    $this->withHeaders($this->headers)->getJson("/api/v1/databases/{$database->uuid}/imports/{$activity->id}")
        ->assertOk()->assertJson(['id' => $activity->id, 'status' => 'finished', 'exit_code' => 0, 'output' => 'restored'])
        ->assertJsonMissingPath('command');

    $activity->properties = $activity->properties->merge(['team_id' => $this->team->id + 1]);
    $activity->save();
    $this->withHeaders($this->headers)->getJson("/api/v1/databases/{$database->uuid}/imports/{$activity->id}")->assertNotFound();
});

test('returns invalid token when the access token team is not a member team', function (string $method, string $path) {
    $this->withoutMiddleware([
        EnsureTokenBelongsToCurrentTeamMember::class,
        ApiAbility::class,
    ]);

    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
    $foreignTeam = Team::factory()->create();
    $plainTextToken = 'no-team';
    $token = $this->user->tokens()->create([
        'name' => 'imports-foreign-team',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['deploy', 'read'],
        'team_id' => $foreignTeam->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->id.'|'.$plainTextToken])
        ->{$method}(sprintf($path, $database->uuid))
        ->assertBadRequest()
        ->assertJson([
            'message' => 'Invalid token.',
            'docs' => 'https://coolify.io/docs/api-reference/authorization',
        ]);
})->with([
    'upload' => ['postJson', '/api/v1/databases/%s/imports/uploads'],
    'create' => ['postJson', '/api/v1/databases/%s/imports'],
    'show' => ['getJson', '/api/v1/databases/%s/imports/1'],
]);

test('restricts database import output to authorized sensitive readers', function (string $resourceType, string $role, array $abilities, bool $bypassAbilityMiddleware, int $expectedStatus, bool $canReadOutput) {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => $role]);
    $this->token->update(['abilities' => $abilities]);
    if ($bypassAbilityMiddleware) {
        $this->withoutMiddleware([ApiAbility::class, EnsureTokenBelongsToCurrentTeamMember::class]);
    }

    if ($resourceType === 'service') {
        $service = Service::factory()->create(['environment_id' => $this->environment->id, 'server_id' => $this->server->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(), 'docker_compose_raw' => "services: {}\n"]);
        $database = ServiceDatabase::create(['name' => 'postgres', 'service_id' => $service->id, 'image' => 'postgres:17']);
        $url = "/api/v1/services/{$service->uuid}/databases/{$database->uuid}/imports";
    } else {
        $database = StandalonePostgresql::create(['name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
        $url = "/api/v1/databases/{$database->uuid}/imports";
    }

    $privateOutput = 'ERROR: Key (token)=(private-api-token) already exists.';
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => json_encode([['order' => 1, 'output' => $privateOutput, 'type' => 'stdout']]),
        'properties' => ['team_id' => $this->team->id, 'type_uuid' => $database->uuid, 'operation' => 'database_import', 'status' => 'error', 'exitCode' => 1],
    ]);

    $response = $this->withHeaders($this->headers)->getJson("{$url}/{$activity->id}")
        ->assertStatus($expectedStatus);

    if ($expectedStatus !== 200) {
        $response->assertDontSee($privateOutput);

        return;
    }

    $response->assertJsonPath('status', 'error')
        ->assertJsonPath('exit_code', 1)
        ->assertJsonPath('output', $canReadOutput ? $privateOutput : null);
    if (! $canReadOutput) {
        $response->assertDontSee($privateOutput);
    }

    $foreignTeam = Team::factory()->create();
    $activity->properties = $activity->properties->merge(['team_id' => $foreignTeam->id]);
    $activity->save();
    $this->withHeaders($this->headers)->getJson("{$url}/{$activity->id}")
        ->assertNotFound()->assertDontSee($privateOutput);
})->with(['standalone', 'service'])->with([
    'admin read only' => ['admin', ['read'], false, 200, false],
    'admin sensitive' => ['admin', ['read', 'read:sensitive'], false, 200, true],
    'owner root' => ['owner', ['root'], false, 200, true],
    'member read only' => ['member', ['read'], false, 200, false],
    'member legacy sensitive token' => ['member', ['read', 'read:sensitive'], false, 403, false],
    'member sensitive without ability middleware' => ['member', ['read', 'read:sensitive'], true, 200, false],
]);
