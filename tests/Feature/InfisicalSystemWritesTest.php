<?php

use App\Exceptions\InfisicalManagedVariableException;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Services\Infisical\InfisicalLock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InfisicalLock::resetDepthForTesting();

    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });

    // servers.team_id is NOT NULL, so the infrastructure fixtures need a team
    // of their own. It is deliberately NOT the team the lock is armed for.
    $this->infraTeam = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->infraTeam->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->infraTeam->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create([
            'server_id' => $this->server->id,
            'network' => 'coolify-test',
        ]);
});

/**
 * A team whose Infisical connection is already enabled, plus an environment.
 *
 * @return array{0: Team, 1: Environment}
 */
function lockedTeamEnvironment(): array
{
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create([
        'team_id' => $team->id,
        'is_enabled' => true,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return [$team, $environment];
}

/**
 * Creates the application AFTER arming the lock, so every system write during
 * resource creation runs against an armed lock — which is the condition under
 * test.
 */
function lockedTeamApplication(string $buildPack = 'dockerfile'): Application
{
    [, $environment] = lockedTeamEnvironment();

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'build_pack' => $buildPack,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

it('still seeds predefined server variables while the lock is armed', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    expect(SharedEnvironmentVariable::query()
        ->where('server_id', $server->id)
        ->pluck('key')
        ->all())
        ->toContain('COOLIFY_SERVER_UUID')
        ->toContain('COOLIFY_SERVER_NAME');
});

it('still seeds and clones the nixpacks variable while the lock is armed', function () {
    // Drives the observer through a REAL system path (Application::booted()'s
    // created hook writing NIXPACKS_NODE_VERSION), not through an asSystem()
    // wrapper in the test. Wrapping it here would put the observer inside the
    // test's own bypass, and the test would pass whether or not the observer
    // was ever wrapped — proving nothing.
    $application = lockedTeamApplication(buildPack: 'nixpacks');

    $rows = EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->where('key', 'NIXPACKS_NODE_VERSION')
        ->get();

    expect($rows->where('is_preview', false))->toHaveCount(1)
        ->and($rows->where('is_preview', true))->toHaveCount(1);
});

it('rejects a bare human create on a locked team', function () {
    $application = lockedTeamApplication();

    expect(fn () => EnvironmentVariable::create([
        'key' => 'TYPED_BY_HAND',
        'value' => 'x',
        'resourceable_type' => $application::class,
        'resourceable_id' => $application->id,
    ]))->toThrow(InfisicalManagedVariableException::class);
});

it('still writes the redis username from the accessor while the lock is armed', function () {
    // The accessor writes during a READ, so merely rendering a page would throw.
    [, $environment] = lockedTeamEnvironment();

    $redis = InfisicalLock::asSystem(fn () => StandaloneRedis::create([
        'name' => 'redis-accessor',
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'redis_password' => 'secret',
    ]));

    EnvironmentVariable::query()
        ->where('resourceable_type', StandaloneRedis::class)
        ->where('resourceable_id', $redis->id)
        ->where('key', 'REDIS_USERNAME')
        ->delete();

    expect(fn () => StandaloneRedis::findOrFail($redis->id)->redis_username)
        ->not->toThrow(InfisicalManagedVariableException::class);

    expect(EnvironmentVariable::query()
        ->where('resourceable_type', StandaloneRedis::class)
        ->where('resourceable_id', $redis->id)
        ->where('key', 'REDIS_USERNAME')
        ->exists())->toBeTrue();
});

it('marks the mongo password self-heal as a system write', function () {
    // mongoInitdbRootPassword() is a get-only accessor that re-encrypts a
    // plaintext value and SAVES during the read. Task 8 puts a guard on this
    // model, at which point rendering a Mongo page on a locked team would
    // throw unless the save is a system write.
    [, $environment] = lockedTeamEnvironment();

    $mongo = InfisicalLock::asSystem(fn () => StandaloneMongodb::create([
        'name' => 'mongo-selfheal',
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'mongo_initdb_root_password' => 'plaintext-not-encrypted',
    ]));

    $observed = null;
    StandaloneMongodb::saving(function () use (&$observed): void {
        $observed = InfisicalLock::isSystemWrite();
    });

    StandaloneMongodb::findOrFail($mongo->id)->mongo_initdb_root_password;

    expect($observed)->toBeTrue();
});
