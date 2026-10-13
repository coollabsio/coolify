<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    Queue::fake();
});

function databaseCreateNumericLimitsHeaders(User $user, int $teamId): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'database-create-numeric-limits-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['write'],
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

function databaseCreateNumericLimitsPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        'server_uuid' => $test->server->uuid,
    ], $overrides);
}

test('numeric resource limits are accepted and stored as strings', function (string $type) {
    $response = $this->withHeaders(databaseCreateNumericLimitsHeaders($this->user, $this->team->id))
        ->postJson("/api/v1/databases/{$type}", databaseCreateNumericLimitsPayload($this, [
            'limits_memory' => 0,
            'limits_memory_swap' => 1024,
            'limits_memory_reservation' => 512,
            'limits_cpus' => 1.5,
            'limits_cpuset' => 1,
        ]))
        ->assertCreated();

    $database = queryDatabaseByUuidWithinTeam($response->json('uuid'), $this->team->id);

    expect($database->limits_memory)->toBe('0')
        ->and($database->limits_memory_swap)->toBe('1024')
        ->and($database->limits_memory_reservation)->toBe('512')
        ->and($database->limits_cpus)->toBe('1.5')
        ->and($database->limits_cpuset)->toBe('1');
})->with(['postgresql', 'mysql', 'mariadb', 'mongodb', 'redis', 'keydb', 'dragonfly', 'clickhouse', 'sqlite']);

test('string resource limits are still accepted', function () {
    $response = $this->withHeaders(databaseCreateNumericLimitsHeaders($this->user, $this->team->id))
        ->postJson('/api/v1/databases/postgresql', databaseCreateNumericLimitsPayload($this, [
            'limits_memory' => '512m',
            'limits_cpus' => '0.5',
        ]))
        ->assertCreated();

    $database = queryDatabaseByUuidWithinTeam($response->json('uuid'), $this->team->id);

    expect($database->limits_memory)->toBe('512m')
        ->and($database->limits_cpus)->toBe('0.5');
});

test('non-scalar or boolean resource limits are rejected', function (string $field, mixed $value) {
    $this->withHeaders(databaseCreateNumericLimitsHeaders($this->user, $this->team->id))
        ->postJson('/api/v1/databases/postgresql', databaseCreateNumericLimitsPayload($this, [$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(StandalonePostgresql::count())->toBe(0);
})->with([
    'array memory' => ['limits_memory', ['512m']],
    'array cpus' => ['limits_cpus', [1.5]],
    'boolean cpus' => ['limits_cpus', true],
    'object memory swap' => ['limits_memory_swap', ['value' => 1]],
]);
