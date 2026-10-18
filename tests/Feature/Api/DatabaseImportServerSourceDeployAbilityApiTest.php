<?php

use App\Actions\Database\StartDatabaseImport;
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
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::firstOrCreate(['server_id' => $server->id, 'network' => 'coolify'], ['uuid' => (string) Str::uuid(), 'name' => 'docker']);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()]);
    $service = Service::factory()->create(['environment_id' => $environment->id, 'server_id' => $server->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(), 'docker_compose_raw' => "services:\n  postgres:\n    image: postgres:17\n"]);
    $serviceDatabase = ServiceDatabase::create(['uuid' => (string) Str::uuid(), 'name' => 'postgres', 'service_id' => $service->id, 'image' => 'postgres:17']);

    $this->importPaths = [
        'standalone' => "/api/v1/databases/{$database->uuid}/imports",
        'service' => "/api/v1/services/{$service->uuid}/databases/{$serviceDatabase->uuid}/imports",
    ];
});

function serverSourceImportToken(User $user, Team $team, array $abilities): string
{
    $token = $user->createToken('server-source-import', $abilities);
    $token->accessToken->forceFill(['team_id' => $team->id])->save();

    return $token->plainTextToken;
}

function expectServerSourceImportStarts(): void
{
    $activity = Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]);
    $action = Mockery::mock(StartDatabaseImport::class);
    $action->shouldReceive('handle')->once()->andReturn($activity);
    app()->instance(StartDatabaseImport::class, $action);
}

dataset('server source import variants', ['standalone', 'service']);

test('a write token cannot import from a server path', function (string $variant) {
    $action = Mockery::mock(StartDatabaseImport::class);
    $action->shouldNotReceive('handle');
    app()->instance(StartDatabaseImport::class, $action);

    $this->withToken(serverSourceImportToken($this->user, $this->team, ['read', 'write']))
        ->postJson($this->importPaths[$variant], ['source' => 'server', 'path' => '/etc/hostname'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy. An import from a server path needs a token with the deploy permission.');
})->with('server source import variants');

test('a write token with deploy or a root token can import from a server path', function (string $variant, array $abilities) {
    expectServerSourceImportStarts();

    $this->withToken(serverSourceImportToken($this->user, $this->team, $abilities))
        ->postJson($this->importPaths[$variant], ['source' => 'server', 'path' => '/tmp/backup.sql'])
        ->assertAccepted();
})->with('server source import variants')->with([
    'write and deploy' => [['read', 'write', 'deploy']],
    'root' => [['root']],
]);

test('a write token can still import from an upload without deploy', function () {
    expectServerSourceImportStarts();

    $this->withToken(serverSourceImportToken($this->user, $this->team, ['read', 'write']))
        ->postJson($this->importPaths['standalone'], ['source' => 'upload', 'upload_id' => (string) Str::uuid()])
        ->assertAccepted();
});
