<?php

use App\Actions\Database\StartDatabase;
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

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    Queue::fake();
});

function databaseCreateInstantDeployHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'database-create-instant-deploy-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

function databaseCreateInstantDeployPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        'server_uuid' => $test->server->uuid,
    ], $overrides);
}

test('write-only token with a "false" string instant_deploy does not start the database', function () {
    $this->withHeaders(databaseCreateInstantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson('/api/v1/databases/postgresql', databaseCreateInstantDeployPayload($this, ['instant_deploy' => 'false']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('instant_deploy');

    expect(StandalonePostgresql::count())->toBe(0);
    StartDatabase::assertNotPushed();
});

test('write-only token with instant_deploy false creates the database without starting it', function () {
    $this->withHeaders(databaseCreateInstantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson('/api/v1/databases/postgresql', databaseCreateInstantDeployPayload($this, ['instant_deploy' => false]))
        ->assertCreated();

    expect(StandalonePostgresql::count())->toBe(1);
    StartDatabase::assertNotPushed();
});

test('write-only token with instant_deploy true is forbidden', function () {
    $this->withHeaders(databaseCreateInstantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson('/api/v1/databases/postgresql', databaseCreateInstantDeployPayload($this, ['instant_deploy' => true]))
        ->assertForbidden();

    expect(StandalonePostgresql::count())->toBe(0);
    StartDatabase::assertNotPushed();
});

test('invalid types for shared create fields return 422', function (string $field, array $input) {
    $this->withHeaders(databaseCreateInstantDeployHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->postJson('/api/v1/databases/postgresql', databaseCreateInstantDeployPayload($this, $input))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(StandalonePostgresql::count())->toBe(0);
    StartDatabase::assertNotPushed();
})->with([
    'instant_deploy' => ['instant_deploy', ['instant_deploy' => 'abc']],
    'is_public' => ['is_public', ['is_public' => 'abc', 'public_port' => 15432]],
    'limits_cpu_shares' => ['limits_cpu_shares', ['limits_cpu_shares' => 'abc']],
]);

test('write and deploy token with instant_deploy true starts the database', function (mixed $instantDeploy) {
    $this->withHeaders(databaseCreateInstantDeployHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->postJson('/api/v1/databases/postgresql', databaseCreateInstantDeployPayload($this, ['instant_deploy' => $instantDeploy]))
        ->assertCreated();

    expect(StandalonePostgresql::count())->toBe(1);
    StartDatabase::assertPushed(1);
})->with([
    'boolean true' => [true],
    'string "1"' => ['1'],
]);
