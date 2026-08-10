<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function attachDb(string $modelClass, array $extra, $destination, $environment)
{
    return $modelClass::create(array_merge([
        'name' => 'test-'.strtolower(class_basename($modelClass)),
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $extra));
}

test('StandaloneDocker::databases() includes attached keydb', function () {
    attachDb(StandaloneKeydb::class, ['keydb_password' => 'pw'], $this->destination, $this->environment);

    expect($this->destination->databases()->count())->toBe(1);
    expect($this->destination->attachedTo())->toBeTrue();
});

test('StandaloneDocker::databases() includes attached dragonfly', function () {
    attachDb(StandaloneDragonfly::class, ['dragonfly_password' => 'pw'], $this->destination, $this->environment);

    expect($this->destination->databases()->count())->toBe(1);
    expect($this->destination->attachedTo())->toBeTrue();
});

test('StandaloneDocker::databases() includes attached clickhouse', function () {
    attachDb(StandaloneClickhouse::class, ['clickhouse_admin_password' => 'pw'], $this->destination, $this->environment);

    expect($this->destination->databases()->count())->toBe(1);
    expect($this->destination->attachedTo())->toBeTrue();
});

test('StandaloneDocker::databases() includes all 8 standalone database types', function () {
    attachDb(StandalonePostgresql::class, ['postgres_password' => 'pw'], $this->destination, $this->environment);
    attachDb(StandaloneRedis::class, ['redis_password' => 'pw'], $this->destination, $this->environment);
    attachDb(StandaloneMongodb::class, ['mongo_initdb_root_password' => 'pw'], $this->destination, $this->environment);
    attachDb(StandaloneMysql::class, ['mysql_root_password' => 'pw', 'mysql_password' => 'pw'], $this->destination, $this->environment);
    attachDb(StandaloneMariadb::class, ['mariadb_root_password' => 'pw', 'mariadb_password' => 'pw'], $this->destination, $this->environment);
    attachDb(StandaloneKeydb::class, ['keydb_password' => 'pw'], $this->destination, $this->environment);
    attachDb(StandaloneDragonfly::class, ['dragonfly_password' => 'pw'], $this->destination, $this->environment);
    attachDb(StandaloneClickhouse::class, ['clickhouse_admin_password' => 'pw'], $this->destination, $this->environment);

    expect($this->destination->databases()->count())->toBe(8);
    expect($this->destination->attachedTo())->toBeTrue();
});
