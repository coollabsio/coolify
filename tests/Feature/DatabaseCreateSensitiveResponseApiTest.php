<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
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

function databaseCreateSensitiveHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'database-create-sensitive-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

function createDatabaseViaApi(object $test, string $engine, array $abilities)
{
    return $test->withHeaders(databaseCreateSensitiveHeaders($test->user, $test->team->id, $abilities))
        ->postJson("/api/v1/databases/{$engine}", [
            'project_uuid' => $test->project->uuid,
            'environment_uuid' => $test->environment->uuid,
            'server_uuid' => $test->server->uuid,
            'is_public' => true,
            'public_port' => 15432,
        ]);
}

$engines = ['postgresql', 'mariadb', 'mysql', 'redis', 'keydb', 'clickhouse', 'mongodb'];

test('create response hides database urls from a token without sensitive read access', function (string $engine) {
    $response = createDatabaseViaApi($this, $engine, ['write'])->assertCreated();

    expect($response->json())->toHaveKey('uuid')
        ->not->toHaveKey('internal_db_url')
        ->not->toHaveKey('external_db_url');
})->with($engines);

test('create response includes database urls for a token with sensitive read access', function (string $engine, array $abilities) {
    $response = createDatabaseViaApi($this, $engine, $abilities)->assertCreated();

    expect($response->json())->toHaveKeys(['uuid', 'internal_db_url', 'external_db_url']);
})->with($engines)->with([
    'read:sensitive' => [['write', 'read:sensitive']],
    'root' => [['root']],
]);
