<?php

use App\Jobs\PostgresqlWalBaseBackupJob;
use App\Jobs\PostgresqlWalHealthCheckJob;
use App\Jobs\PostgresqlWalRetentionJob;
use App\Jobs\ScheduledJobManager;
use App\Models\Environment;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\PostgresqlWalArchivingFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'constants.coolify.self_hosted' => true,
    ]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');

    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'backup_failure_email_notifications' => true,
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'server_timezone' => 'UTC',
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->database = createPostgresqlWalJobDatabase($this->team, $this->destination);
    $this->storage = createPostgresqlWalJobStorage($this->team);
    $this->configuration = createPostgresqlWalJobConfiguration($this->team, $this->database, $this->storage);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('uses one shared repository lock for base backups and retention', function () {
    $baseBackupJob = new PostgresqlWalBaseBackupJob($this->configuration);
    $retentionJob = new PostgresqlWalRetentionJob($this->configuration);
    $baseBackupMiddleware = $baseBackupJob->middleware()[0];
    $retentionMiddleware = $retentionJob->middleware()[0];
    $expectedLockKey = 'laravel-queue-overlap:'.PostgresqlWalBaseBackupJob::repositoryLockKey($this->configuration->id);

    expect($baseBackupMiddleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($retentionMiddleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($baseBackupMiddleware->shareKey)->toBeTrue()
        ->and($retentionMiddleware->shareKey)->toBeTrue()
        ->and($baseBackupMiddleware->getLockKey($baseBackupJob))->toBe($expectedLockKey)
        ->and($retentionMiddleware->getLockKey($retentionJob))->toBe($expectedLockKey)
        ->and($baseBackupMiddleware->expiresAfter)->toBeGreaterThan($baseBackupJob->timeout)
        ->and($retentionMiddleware->expiresAfter)->toBeGreaterThan($retentionJob->timeout)
        ->and($baseBackupMiddleware->releaseAfter)->toBeNull()
        ->and($retentionMiddleware->releaseAfter)->toBeNull();
});

it('runs a base backup against resolved PGDATA and then applies full-backup retention', function () {
    Process::fake([
        '*PG_VERSION*' => new FakeProcessResult(output: "/var/lib/postgresql/data/custom\n"),
        '*backup-push*' => new FakeProcessResult(output: "INFO: Wrote backup with name base_00000001000000000000000A\n"),
        '*delete retain FULL*' => new FakeProcessResult(output: "INFO: retention complete\n"),
        '*' => new FakeProcessResult,
    ]);

    (new PostgresqlWalBaseBackupJob($this->configuration))->handle();

    $executions = $this->configuration->executions()->reorder('id')->get();
    expect($executions)->toHaveCount(2)
        ->and($executions[0]->operation)->toBe('base_backup')
        ->and($executions[0]->status)->toBe('success')
        ->and($executions[0]->backup_name)->toBe('base_00000001000000000000000A')
        ->and($executions[1]->operation)->toBe('retention')
        ->and($executions[1]->status)->toBe('success')
        ->and($this->configuration->fresh()->status)->toBe('healthy')
        ->and($this->configuration->fresh()->last_successful_base_backup_at)->not->toBeNull();

    Process::assertRan(fn ($process) => str_contains($process->command, '. /etc/wal-g/env')
        && str_contains($process->command, 'backup-push')
        && str_contains($process->command, '/var/lib/postgresql/data/custom'));
    Process::assertRan(fn ($process) => str_contains($process->command, '. /etc/wal-g/env')
        && str_contains($process->command, 'delete retain FULL')
        && str_contains($process->command, '--use-sentinel-time --confirm')
        && str_contains($process->command, "'7'"));
});

it('skips base backups until the archive configuration is applied', function () {
    Process::fake();
    $this->configuration->update(['status' => 'pending_restart']);

    (new PostgresqlWalBaseBackupJob($this->configuration->fresh()))->handle();

    expect($this->configuration->executions)->toHaveCount(0);
    Process::assertNothingRan();
});

it('records a failed base backup and marks the configuration failed', function () {
    Process::fake([
        '*PG_VERSION*' => new FakeProcessResult(output: "/var/lib/postgresql/data\n"),
        '*backup-push*' => Process::result(errorOutput: 'archive unavailable', exitCode: 1),
        '*' => new FakeProcessResult,
    ]);

    expect(fn () => (new PostgresqlWalBaseBackupJob($this->configuration))->handle())
        ->toThrow(RuntimeException::class, 'archive unavailable');

    $execution = $this->configuration->executions()->firstOrFail();
    expect($execution->operation)->toBe('base_backup')
        ->and($execution->status)->toBe('failed')
        ->and($this->configuration->fresh()->status)->toBe('failed');
});

it('marks an applied healthy archiver healthy and records its latest WAL', function () {
    $this->configuration->update([
        'status' => 'pending_restart',
        'last_successful_base_backup_at' => now(),
    ]);
    Process::fake([
        '*pg_stat_archiver*' => new FakeProcessResult(output: "on|/usr/local/bin/coolify-walg-archive %p|12|0|00000001000000000000000C|2026-07-24 02:00:00+00||\n"),
        '*' => new FakeProcessResult,
    ]);

    (new PostgresqlWalHealthCheckJob($this->configuration->fresh()))->handle();

    $configuration = $this->configuration->fresh();
    $execution = $configuration->executions()->firstOrFail();
    expect($configuration->status)->toBe('healthy')
        ->and($configuration->last_archived_wal)->toBe('00000001000000000000000C')
        ->and($configuration->last_archived_at)->not->toBeNull()
        ->and($execution->operation)->toBe('health_check')
        ->and($execution->status)->toBe('success');
});

it('keeps a healthy archiver in warning state until its first base backup', function () {
    Queue::fake();
    Process::fake([
        '*pg_stat_archiver*' => new FakeProcessResult(output: "on|/usr/local/bin/coolify-walg-archive %p|1|0||||\n"),
        '*' => new FakeProcessResult,
    ]);

    (new PostgresqlWalHealthCheckJob($this->configuration))->handle();

    expect($this->configuration->fresh()->status)->toBe('warning')
        ->and($this->configuration->fresh()->last_health_message)->toContain('no successful WAL-G base backup');
    Queue::assertPushed(
        PostgresqlWalBaseBackupJob::class,
        fn (PostgresqlWalBaseBackupJob $job) => $job->configuration->is($this->configuration),
    );
});

it('makes initial base backup dispatches unique per WAL-G configuration', function () {
    $job = new PostgresqlWalBaseBackupJob($this->configuration);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $this->configuration->id)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('re-baselines a reset archiver failure counter without treating it as recovery', function () {
    Notification::fake();
    $this->configuration->update([
        'status' => 'failed',
        'last_failed_count' => 5,
        'last_successful_base_backup_at' => now(),
    ]);
    Process::fake([
        '*pg_stat_archiver*' => new FakeProcessResult(output: "on|/usr/local/bin/coolify-walg-archive %p|0|0||||\n"),
        '*' => new FakeProcessResult,
    ]);

    (new PostgresqlWalHealthCheckJob($this->configuration->fresh()))->handle();

    expect($this->configuration->fresh()->status)->toBe('healthy')
        ->and($this->configuration->fresh()->last_failed_count)->toBe(0)
        ->and($this->configuration->fresh()->last_health_message)->toContain('re-baselined');
    Notification::assertNothingSent();
});

it('notifies the team when PostgreSQL reports a new WAL archive failure', function () {
    Notification::fake();
    $this->configuration->update([
        'status' => 'healthy',
        'last_failed_count' => 1,
        'last_successful_base_backup_at' => now(),
    ]);
    Process::fake([
        '*pg_stat_archiver*' => new FakeProcessResult(output: "on|/usr/local/bin/coolify-walg-archive %p|10|2|00000001000000000000000A|2026-07-24 02:00:00+00|00000001000000000000000B|2026-07-24 02:01:00+00\n"),
        '*' => new FakeProcessResult,
    ]);

    (new PostgresqlWalHealthCheckJob($this->configuration->fresh()))->handle();

    expect($this->configuration->fresh()->status)->toBe('failed')
        ->and($this->configuration->fresh()->last_failed_count)->toBe(2)
        ->and($this->configuration->executions()->firstOrFail()->status)->toBe('failed');
    Notification::assertSentTo(
        $this->team,
        PostgresqlWalArchivingFailed::class,
        fn (PostgresqlWalArchivingFailed $notification) => $notification->database->is($this->database)
            && str_contains($notification->output, 'data disk fills'),
    );
});

it('keeps monitoring a detached configuration and raises the disk-fill warning', function () {
    Notification::fake();
    $this->configuration->update([
        'enabled' => false,
        'status' => 'failed',
        's3_storage_id' => null,
    ]);
    Process::fake([
        '*pg_stat_archiver*' => new FakeProcessResult(output: "on|/usr/local/bin/coolify-walg-archive %p|10|1||||\n"),
        '*' => new FakeProcessResult,
    ]);

    (new PostgresqlWalHealthCheckJob($this->configuration->fresh()))->handle();

    expect($this->configuration->fresh()->status)->toBe('failed')
        ->and($this->configuration->fresh()->last_health_message)->toContain('data disk fills');
    Notification::assertSentTo($this->team, PostgresqlWalArchivingFailed::class);
});

it('marks a disabled configuration terminal after archiving is physically off', function () {
    Notification::fake();
    $this->configuration->update([
        'enabled' => false,
        'status' => 'failed',
        's3_storage_id' => null,
    ]);
    Process::fake([
        '*pg_stat_archiver*' => new FakeProcessResult(output: "off|(disabled)|0|0||||\n"),
        '*' => new FakeProcessResult,
    ]);

    (new PostgresqlWalHealthCheckJob($this->configuration->fresh()))->handle();

    expect($this->configuration->fresh()->status)->toBe('disabled');
    Notification::assertNothingSent();
});

it('dispatches due base backups only for applied configs and health checks for every non-disabled config', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 24, 3, 0, 0, 'UTC'));
    Queue::fake();
    $this->configuration->update(['base_backup_frequency' => '* * * * *']);
    $pendingConfiguration = createPostgresqlWalJobConfiguration(
        $this->team,
        createPostgresqlWalJobDatabase($this->team, $this->destination, 'pending-postgres'),
        $this->storage,
        ['status' => 'pending_restart'],
    );
    $disabledConfiguration = createPostgresqlWalJobConfiguration(
        $this->team,
        createPostgresqlWalJobDatabase($this->team, $this->destination, 'disabled-postgres'),
        $this->storage,
        ['enabled' => false, 'status' => 'disabled'],
    );
    $failedConfiguration = createPostgresqlWalJobConfiguration(
        $this->team,
        createPostgresqlWalJobDatabase($this->team, $this->destination, 'failed-postgres'),
        $this->storage,
        ['enabled' => false, 'status' => 'failed', 's3_storage_id' => null],
    );

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(PostgresqlWalBaseBackupJob::class, 1);
    Queue::assertPushed(
        PostgresqlWalBaseBackupJob::class,
        fn (PostgresqlWalBaseBackupJob $job) => $job->configuration->is($this->configuration),
    );
    Queue::assertNotPushed(
        PostgresqlWalBaseBackupJob::class,
        fn (PostgresqlWalBaseBackupJob $job) => $job->configuration->is($pendingConfiguration),
    );
    Queue::assertPushed(PostgresqlWalHealthCheckJob::class, 3);
    Queue::assertPushed(
        PostgresqlWalHealthCheckJob::class,
        fn (PostgresqlWalHealthCheckJob $job) => $job->configuration->is($pendingConfiguration),
    );
    Queue::assertPushed(
        PostgresqlWalHealthCheckJob::class,
        fn (PostgresqlWalHealthCheckJob $job) => $job->configuration->is($failedConfiguration),
    );
    Queue::assertNotPushed(
        PostgresqlWalHealthCheckJob::class,
        fn (PostgresqlWalHealthCheckJob $job) => $job->configuration->is($disabledConfiguration),
    );
});

function createPostgresqlWalJobDatabase(
    Team $team,
    StandaloneDocker $destination,
    string $name = 'wal-postgres',
): StandalonePostgresql {
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return StandalonePostgresql::create([
        'name' => $name,
        'image' => 'ghcr.io/coollabsio/postgres-walg:16',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'running:healthy',
        'is_public' => false,
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createPostgresqlWalJobStorage(Team $team): S3Storage
{
    return S3Storage::create([
        'name' => 'WAL archive',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createPostgresqlWalJobConfiguration(
    Team $team,
    StandalonePostgresql $database,
    S3Storage $storage,
    array $attributes = [],
): PostgresqlWalBackupConfiguration {
    return PostgresqlWalBackupConfiguration::create(array_merge([
        'team_id' => $team->id,
        'standalone_postgresql_id' => $database->id,
        's3_storage_id' => $storage->id,
        'postgres_major_version' => 16,
    ], $attributes));
}
