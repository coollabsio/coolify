<?php

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartMariadb;
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
use Tests\TestCase;

/**
 * Regression tests for database healthcheck command injection.
 *
 * Docker CMD-SHELL healthchecks pass the string to /bin/sh -c, enabling command injection
 * via user-controlled DB username/password/database fields. The fix converts all affected
 * healthchecks to CMD exec-form arrays, which bypass the shell entirely.
 */
uses(TestCase::class, RefreshDatabase::class);

const HEALTHCHECK_INJECTION_PAYLOAD = "a'; touch /tmp/pwn; '\$(id)";

/**
 * Starts the database with a captured executor and returns the healthcheck test of the written compose file.
 *
 * @return list<string>
 */
function startedDatabaseHealthcheck(string $startAction, object $database): array
{
    $executor = new class
    {
        public array $commands = [];

        public function execute(array $commands, $database, Activity $activity): Activity
        {
            $this->commands = $commands;

            return $activity;
        }
    };
    app()->instance(DatabaseStartCommandExecutor::class, $executor);

    $startAction::run($database->fresh(), new Activity);

    foreach ($executor->commands as $command) {
        if (preg_match("#^echo '([^']+)' \| base64 -d \| tee \S+/docker-compose\.yml#", $command, $matches)) {
            return Yaml::parse(base64_decode($matches[1]))['services'][$database->uuid]['healthcheck']['test'];
        }
    }

    throw new RuntimeException('No docker-compose.yml was written.');
}

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
});

test('postgresql healthcheck passes the user and database as exec-form arguments', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'postgres_user' => HEALTHCHECK_INJECTION_PAYLOAD,
        'postgres_db' => HEALTHCHECK_INJECTION_PAYLOAD.'db',
    ]);

    expect(startedDatabaseHealthcheck(StartPostgresql::class, $database))
        ->toBe(['CMD', 'psql', '-U', HEALTHCHECK_INJECTION_PAYLOAD, '-d', HEALTHCHECK_INJECTION_PAYLOAD.'db', '-c', 'SELECT 1']);
});

test('clickhouse healthcheck passes the user and password as exec-form arguments', function () {
    $database = create_standalone_clickhouse($this->environment->id, $this->destination, [
        'clickhouse_admin_user' => HEALTHCHECK_INJECTION_PAYLOAD,
        'clickhouse_admin_password' => HEALTHCHECK_INJECTION_PAYLOAD.'pw',
    ]);

    expect(startedDatabaseHealthcheck(StartClickhouse::class, $database))
        ->toBe(['CMD', 'clickhouse-client', '--user', HEALTHCHECK_INJECTION_PAYLOAD, '--password', HEALTHCHECK_INJECTION_PAYLOAD.'pw', '--query', 'SELECT 1']);
});

test('mysql healthcheck passes the root password as an exec-form argument', function () {
    $database = create_standalone_mysql($this->environment->id, $this->destination, [
        'mysql_root_password' => HEALTHCHECK_INJECTION_PAYLOAD,
    ]);

    expect(startedDatabaseHealthcheck(StartMysql::class, $database))
        ->toBe(['CMD', 'mysqladmin', 'ping', '-h', 'localhost', '-u', 'root', '-p'.HEALTHCHECK_INJECTION_PAYLOAD]);
});

test('mariadb healthcheck uses the fixed healthcheck script without user fields', function () {
    $database = create_standalone_mariadb($this->environment->id, $this->destination, [
        'mariadb_user' => HEALTHCHECK_INJECTION_PAYLOAD,
        'mariadb_password' => HEALTHCHECK_INJECTION_PAYLOAD.'pw',
        'mariadb_root_password' => HEALTHCHECK_INJECTION_PAYLOAD.'root',
    ]);

    expect(startedDatabaseHealthcheck(StartMariadb::class, $database))
        ->toBe(['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized']);
});
