<?php

use App\Livewire\Project\Shared\Danger;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->withoutDefer();
});

function createFlushableDatabase(string $model, array $attributes = []): StandaloneRedis|StandaloneKeydb|StandaloneDragonfly
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
        'user' => 'deploy',
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);

    return $model::create([
        'name' => 'cache',
        'image' => 'redis:7',
        'environment_id' => Environment::factory()->create(['project_id' => $project->id])->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        ...$attributes,
    ]);
}

function actingAsFlushUser(Team $team, string $role): void
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

it('flushes every key with the engine CLI', function (string $model, array $attributes, string $cli) {
    $database = createFlushableDatabase($model, $attributes);
    actingAsFlushUser($database->team(), 'owner');
    Process::fake(['*' => Process::result(output: 'OK')]);

    Livewire::test(Danger::class, ['resource' => $database])
        ->call('flush', 'password')
        ->assertReturned(true)
        ->assertDispatched('success');

    Process::assertRan(fn ($process) => str_contains($process->command, "sudo bash -c 'docker exec {$database->uuid} sh -c '\\''REDISCLI_AUTH=\"\$REDIS_PASSWORD\" {$cli} FLUSHALL ASYNC'\\'''"));
    expect(AuditEvent::query()->where('event', 'ui.database.flushed')->where('resource_uuid', $database->uuid)->exists())->toBeTrue();
})->with([
    'redis' => [StandaloneRedis::class, [], 'redis-cli'],
    'keydb' => [StandaloneKeydb::class, ['keydb_password' => 'secret'], 'keydb-cli'],
    'dragonfly with tls' => [StandaloneDragonfly::class, ['dragonfly_password' => 'secret', 'enable_ssl' => true], 'redis-cli --tls --cacert /etc/dragonfly/certs/coolify-ca.crt --cert /etc/dragonfly/certs/server.crt --key /etc/dragonfly/certs/server.key'],
]);

it('reports an error when the database does not reply OK', function () {
    $database = createFlushableDatabase(StandaloneRedis::class);
    actingAsFlushUser($database->team(), 'owner');
    Process::fake(['*' => Process::result(output: 'NOAUTH Authentication required.')]);

    Livewire::test(Danger::class, ['resource' => $database])
        ->call('flush', 'password')
        ->assertReturned(false)
        ->assertDispatched('error', 'NOAUTH Authentication required.');

    expect(AuditEvent::query()->where('event', 'ui.database.flushed')->exists())->toBeFalse();
});

it('rejects an incorrect password without flushing', function () {
    $database = createFlushableDatabase(StandaloneRedis::class);
    actingAsFlushUser($database->team(), 'owner');
    Process::fake();

    Livewire::test(Danger::class, ['resource' => $database])
        ->call('flush', 'wrong-password')
        ->assertReturned('The provided password is incorrect.');

    Process::assertNothingRan();
});

it('forbids flushing without permission to manage the database', function (string $role, bool $sameTeam) {
    $database = createFlushableDatabase(StandaloneRedis::class);
    actingAsFlushUser($sameTeam ? $database->team() : Team::factory()->create(), $role);
    Process::fake();

    Livewire::test(Danger::class, ['resource' => $database])
        ->call('flush', 'password')
        ->assertDispatched('error');

    Process::assertNothingRan();
})->with([
    'team member' => ['member', true],
    'admin of another team' => ['admin', false],
]);
