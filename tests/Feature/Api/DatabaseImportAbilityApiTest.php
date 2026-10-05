<?php

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

function databaseImportAbilityToken(User $user, Team $team, array $abilities): string
{
    $token = $user->createToken('import-ability', $abilities);
    $token->accessToken->forceFill(['team_id' => $team->id])->save();

    return $token->plainTextToken;
}

dataset('database import variants', ['standalone', 'service']);
dataset('database import mutations', ['upload' => ['/uploads'], 'create' => ['']]);

test('a deploy token cannot upload or start a database import', function (string $variant, string $suffix) {
    $token = databaseImportAbilityToken($this->user, $this->team, ['read', 'deploy']);

    $this->withToken($token)
        ->postJson($this->importPaths[$variant].$suffix, ['source' => 'server', 'path' => '/tmp/a.sql', 'dump_all' => true])
        ->assertForbidden();
})->with('database import variants')->with('database import mutations');

test('a write or root token passes the ability check for database imports', function (string $variant, string $suffix, array $abilities) {
    $token = databaseImportAbilityToken($this->user, $this->team, $abilities);

    $this->withToken($token)
        ->postJson($this->importPaths[$variant].$suffix, [])
        ->assertUnprocessable();
})->with('database import variants')->with('database import mutations')->with([
    'write' => [['read', 'write']],
    'root' => [['root']],
]);

test('a non-numeric import activity id returns not found', function (string $variant) {
    $token = databaseImportAbilityToken($this->user, $this->team, ['read']);

    $this->withToken($token)
        ->getJson($this->importPaths[$variant].'/not-a-number')
        ->assertNotFound();
})->with('database import variants');
