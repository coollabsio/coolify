<?php

use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartRedis;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->firstOrCreate(['id' => 0]);

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->databaseAttributes = [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];
});

/**
 * Compose interpolates `$` in `command:` before it splits the string into words,
 * so the password must be shell-quoted and every `$` must be doubled.
 */
function redisFamilyStartCommand(object $action, object $database): string
{
    $action->database = $database;
    (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);

    return (new ReflectionMethod($action, 'buildStartCommand'))->invoke($action);
}

test('redis start command quotes the password and keeps dollars literal', function () {
    $redis = StandaloneRedis::forceCreate([
        'uuid' => 'redis-password-test',
        'name' => 'Redis password test',
        'image' => 'redis:7-alpine',
        ...$this->databaseAttributes,
    ]);
    $redis->runtime_environment_variables()->create([
        'key' => 'REDIS_PASSWORD',
        'value' => "p4\$\$w'o rd\$X",
    ]);

    expect(redisFamilyStartCommand(new StartRedis, $redis))
        ->toBe("redis-server --requirepass 'p4\$\$\$\$w'\\''o rd\$\$X' --appendonly yes");
});

test('keydb start command keeps dollars literal for exact password variables', function () {
    $keydb = StandaloneKeydb::forceCreate([
        'uuid' => 'keydb-password-test',
        'name' => 'KeyDB password test',
        'image' => 'eqalpha/keydb:latest',
        'keydb_password' => 'column-password',
        ...$this->databaseAttributes,
    ]);
    $keydb->runtime_environment_variables()->create([
        'key' => 'REDIS_PASSWORD',
        'value' => 'p4$$word',
    ]);

    expect(redisFamilyStartCommand(new StartKeydb, $keydb))
        ->toBe("keydb-server --requirepass 'p4\$\$\$\$word' --appendonly yes");
});

test('dragonfly start command keeps dollars literal for exact password variables', function () {
    $dragonfly = StandaloneDragonfly::forceCreate([
        'uuid' => 'dragonfly-password-test',
        'name' => 'Dragonfly password test',
        'image' => 'docker.dragonflydb.io/dragonflydb/dragonfly',
        'dragonfly_password' => 'column-password',
        ...$this->databaseAttributes,
    ]);
    $dragonfly->runtime_environment_variables()->create([
        'key' => 'REDIS_PASSWORD',
        'value' => 'p4$$word',
    ]);

    expect(redisFamilyStartCommand(new StartDragonfly, $dragonfly))
        ->toBe("dragonfly --requirepass 'p4\$\$\$\$word'");
});

test('keydb and dragonfly healthchecks use the compose-escaped password', function () {
    expect(file_get_contents(__DIR__.'/../../app/Actions/Database/StartKeydb.php'))
        ->toContain("'CMD', 'keydb-cli', '--pass', \$this->resolvedRedisPassword, 'ping'");
    expect(file_get_contents(__DIR__.'/../../app/Actions/Database/StartDragonfly.php'))
        ->toContain("'CMD', 'redis-cli', '-a', \$this->resolvedRedisPassword, 'ping'");
});
