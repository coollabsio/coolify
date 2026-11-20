<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $this->user->createToken('test-token', ['read'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->otherDestination = StandaloneDocker::query()->where('server_id', $this->otherServer->id)->firstOrFail();

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
    $this->stagingEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->otherProject = Project::factory()->create(['team_id' => $this->team->id]);

    $placement = fn (Environment $environment, StandaloneDocker $destination) => [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];

    StandalonePostgresql::create(['name' => 'Main Postgres', 'status' => 'running:healthy', 'postgres_password' => 'secret'] + $placement($this->environment, $this->destination));
    StandaloneMysql::create(['name' => 'Staging Mysql', 'status' => 'exited', 'mysql_password' => 'secret', 'mysql_root_password' => 'secret'] + $placement($this->stagingEnvironment, $this->destination));
    StandaloneRedis::create(['name' => 'Cache Redis', 'status' => 'running:unknown', 'redis_password' => 'secret'] + $placement($this->otherProject->environments()->first(), $this->otherDestination));
});

function listDatabases(array $query = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->bearerToken])
        ->getJson('/api/v1/databases'.($query ? '?'.http_build_query($query) : ''));
}

function listedDatabaseNames(TestResponse $response): array
{
    return collect($response->json())->pluck('name')->all();
}

test('without parameters it returns all team databases of every type ordered by name', function () {
    $response = listDatabases()->assertOk();

    expect(listedDatabaseNames($response))->toBe(['Cache Redis', 'Main Postgres', 'Staging Mysql'])
        ->and(collect($response->json())->pluck('database_type')->all())->toBe(['standalone-redis', 'standalone-postgresql', 'standalone-mysql'])
        ->and($response->headers->has('X-Total-Count'))->toBeFalse();
});

test('it filters by project, environment, and server uuid', function () {
    expect(listedDatabaseNames(listDatabases(['project_uuid' => $this->project->uuid])->assertOk()))->toBe(['Main Postgres', 'Staging Mysql'])
        ->and(listedDatabaseNames(listDatabases(['environment_uuid' => $this->stagingEnvironment->uuid])->assertOk()))->toBe(['Staging Mysql'])
        ->and(listedDatabaseNames(listDatabases(['server_uuid' => $this->otherServer->uuid])->assertOk()))->toBe(['Cache Redis']);
});

test('it filters by status and name case-insensitively', function () {
    expect(listedDatabaseNames(listDatabases(['status' => 'RUNNING'])->assertOk()))->toBe(['Cache Redis', 'Main Postgres'])
        ->and(listedDatabaseNames(listDatabases(['name' => 'mysql'])->assertOk()))->toBe(['Staging Mysql']);
});

test('unknown uuids and uuids of another team give an empty list', function () {
    $otherTeam = Team::factory()->create();
    $foreignServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $foreignDestination = StandaloneDocker::query()->where('server_id', $foreignServer->id)->firstOrFail();
    $foreignProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    StandalonePostgresql::create([
        'name' => 'Foreign Postgres',
        'postgres_password' => 'secret',
        'environment_id' => $foreignProject->environments()->first()->id,
        'destination_id' => $foreignDestination->id,
        'destination_type' => $foreignDestination->getMorphClass(),
    ]);

    listDatabases(['project_uuid' => 'does-not-exist'])->assertOk()->assertExactJson([]);
    listDatabases(['project_uuid' => $foreignProject->uuid])->assertOk()->assertExactJson([]);
    listDatabases(['environment_uuid' => $foreignProject->environments()->first()->uuid])->assertOk()->assertExactJson([]);
    listDatabases(['server_uuid' => $foreignServer->uuid])->assertOk()->assertExactJson([]);
    listDatabases(['server_uuid' => $foreignServer->uuid, 'per_page' => 10])->assertOk()->assertHeader('X-Total-Count', '0');
    expect(listedDatabaseNames(listDatabases()))->not->toContain('Foreign Postgres');
});

test('it paginates across database types with a total count header', function () {
    $first = listDatabases(['per_page' => 2])->assertOk()->assertHeader('X-Total-Count', '3');
    $second = listDatabases(['page' => 2, 'per_page' => 2])->assertOk()->assertHeader('X-Total-Count', '3');

    expect(listedDatabaseNames($first))->toBe(['Cache Redis', 'Main Postgres'])
        ->and(listedDatabaseNames($second))->toBe(['Staging Mysql']);
});

test('it rejects invalid pagination parameters', function () {
    listDatabases(['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    listDatabases(['page' => 0])->assertStatus(422)->assertJsonValidationErrors(['page']);
});
