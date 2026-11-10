<?php

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Database\StartSqlite;
use App\Jobs\CoolifyTask;
use App\Jobs\DatabaseStartJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const DATABASE_START_TEST_SECRET = 'S3cretStartPassw0rdXyZ';

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);
    Process::fake(fn () => Process::result(output: 'OK'));
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * The remote scripts that ran, with the base64 files that they write decoded.
 */
function databaseStartRemoteScripts(): string
{
    $scripts = [];
    Process::assertRan(function ($process) use (&$scripts) {
        $script = remoteProcessScript($process);
        preg_match_all("~echo '([A-Za-z0-9+/=]+)' \\| base64 -d~", $script, $files);
        $scripts = [...$scripts, $script, ...array_map('base64_decode', $files[1])];

        return true;
    });

    return implode("\n", $scripts);
}

it('runs database start commands without persisting them through remote process', function (string $action, Closure $create, ?string $passwordAttribute) {
    $database = $create($this->environment->id, $this->destination);
    if ($passwordAttribute !== null) {
        $database->forceFill([$passwordAttribute => DATABASE_START_TEST_SECRET])->save();
    }
    $activity = activity()->withProperties(['status' => 'queued'])->log('[]');

    $result = $action::run($database->fresh(), $activity);

    expect($result->is($activity))->toBeTrue()
        ->and(Activity::query()->count())->toBe(1)
        ->and(json_encode(Activity::query()->sole()->properties))->not->toContain('docker compose')
        ->not->toContain('base64 -d')
        ->not->toContain(DATABASE_START_TEST_SECRET);
    Queue::assertNotPushed(CoolifyTask::class);
    $scripts = databaseStartRemoteScripts();
    expect($scripts)->toContain('docker compose');
    if ($passwordAttribute !== null) {
        expect($scripts)->toContain(DATABASE_START_TEST_SECRET);
    }
})->with([
    'clickhouse' => [StartClickhouse::class, fn (...$arguments) => create_standalone_clickhouse(...$arguments), 'clickhouse_admin_password'],
    'dragonfly' => [StartDragonfly::class, fn (...$arguments) => create_standalone_dragonfly(...$arguments), 'dragonfly_password'],
    'keydb' => [StartKeydb::class, fn (...$arguments) => create_standalone_keydb(...$arguments), 'keydb_password'],
    'mariadb' => [StartMariadb::class, fn (...$arguments) => create_standalone_mariadb(...$arguments), 'mariadb_root_password'],
    'mongodb' => [StartMongodb::class, fn (...$arguments) => create_standalone_mongodb(...$arguments), 'mongo_initdb_root_password'],
    'mysql' => [StartMysql::class, fn (...$arguments) => create_standalone_mysql(...$arguments), 'mysql_root_password'],
    'postgresql' => [StartPostgresql::class, fn (...$arguments) => create_standalone_postgresql(...$arguments), 'postgres_password'],
    'redis' => [StartRedis::class, fn ($environmentId, $destination) => create_standalone_redis($environmentId, $destination, ['redis_password' => DATABASE_START_TEST_SECRET]), null],
    'sqlite' => [StartSqlite::class, fn (...$arguments) => create_standalone_sqlite(...$arguments), null],
]);

it('queues database starts with identifiers instead of generated commands', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination);
    $database->forceFill(['postgres_password' => DATABASE_START_TEST_SECRET])->save();

    $activity = StartDatabase::run($database->fresh());

    expect($activity)->toBeInstanceOf(Activity::class);
    Queue::assertPushed(DatabaseStartJob::class, fn (DatabaseStartJob $job) => $job->databaseClass === StandalonePostgresql::class
        && $job->databaseId === $database->id
        && $job->teamId === $this->team->id
        && $job->activityId === $activity->id
        && ! str_contains(serialize($job), DATABASE_START_TEST_SECRET)
        && ! str_contains(serialize($job), 'docker compose'));
    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'docker compose'));
    expect(json_encode($activity->fresh()->properties))->not->toContain(DATABASE_START_TEST_SECRET);
});
