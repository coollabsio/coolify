<?php

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake();
    Queue::fake();
    Server::flushIdentityMap();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->executor = new class
    {
        public array $commands = [];

        public function execute(array $commands, $database, Activity $activity): Activity
        {
            $this->commands = $commands;

            return $activity;
        }
    };
    app()->instance(DatabaseStartCommandExecutor::class, $this->executor);
});

/**
 * @return array<int, string>
 */
function databaseStartComposeEnvironment(object $executor, string $uuid): array
{
    foreach ($executor->commands as $command) {
        if (preg_match("#^echo '([^']+)' \| base64 -d \| tee \S+/docker-compose\.yml#", $command, $matches)) {
            return Yaml::parse(base64_decode($matches[1]))['services'][$uuid]['environment'];
        }
    }

    throw new RuntimeException('No docker-compose.yml was written.');
}

it('adds the Postgres defaults when user keys or values only contain the default key name', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['postgres_password' => 'model-secret', 'postgres_db' => 'appdb']);
    $database->runtime_environment_variables()->create(['key' => 'POSTGRES_PASSWORD_FILE', 'value' => '/x']);
    $database->runtime_environment_variables()->create(['key' => 'NOTE', 'value' => 'uses POSTGRES_DB and POSTGRES_USER']);

    StartPostgresql::run($database->fresh(), new Activity);

    expect(databaseStartComposeEnvironment($this->executor, $database->uuid))
        ->toContain('POSTGRES_PASSWORD_FILE=/x')
        ->toContain('POSTGRES_PASSWORD=model-secret')
        ->toContain('POSTGRES_DB=appdb')
        ->toContain('POSTGRES_USER=postgres');
});

it('does not add a Postgres default when the user sets the exact key', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['postgres_db' => 'appdb']);
    $database->runtime_environment_variables()->create(['key' => 'POSTGRES_DB', 'value' => 'customdb']);

    StartPostgresql::run($database->fresh(), new Activity);

    $postgresDbEntries = collect(databaseStartComposeEnvironment($this->executor, $database->uuid))
        ->filter(fn (string $entry) => str($entry)->startsWith('POSTGRES_DB='))
        ->values()
        ->all();

    expect($postgresDbEntries)->toBe(['POSTGRES_DB=customdb']);
});

it('adds the password default when the user only sets a *_FILE variant', function (string $action, string $factory, array $data, string $userKey, string $expected) {
    $database = $factory($this->environment->id, $this->destination, $data);
    $database->runtime_environment_variables()->create(['key' => $userKey, 'value' => '/run/secrets/x']);

    $action::run($database->fresh(), new Activity);

    expect(databaseStartComposeEnvironment($this->executor, $database->uuid))
        ->toContain("{$userKey}=/run/secrets/x")
        ->toContain($expected);
})->with([
    'mysql' => [StartMysql::class, 'create_standalone_mysql', ['mysql_root_password' => 'root-secret'], 'MYSQL_ROOT_PASSWORD_FILE', 'MYSQL_ROOT_PASSWORD=root-secret'],
    'mariadb' => [StartMariadb::class, 'create_standalone_mariadb', ['mariadb_root_password' => 'root-secret'], 'MARIADB_ROOT_PASSWORD_FILE', 'MARIADB_ROOT_PASSWORD=root-secret'],
    'mongodb' => [StartMongodb::class, 'create_standalone_mongodb', ['mongo_initdb_root_password' => 'root-secret'], 'MONGO_INITDB_ROOT_PASSWORD_FILE', 'MONGO_INITDB_ROOT_PASSWORD=root-secret'],
    'clickhouse' => [StartClickhouse::class, 'create_standalone_clickhouse', ['clickhouse_admin_password' => 'root-secret'], 'CLICKHOUSE_PASSWORD_FILE', 'CLICKHOUSE_PASSWORD=root-secret'],
]);
