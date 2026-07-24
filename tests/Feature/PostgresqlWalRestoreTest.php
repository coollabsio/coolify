<?php

use App\Jobs\PostgresqlWalBaseBackupJob;
use App\Jobs\PostgresqlWalRestoreJob;
use App\Models\Environment;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PostgresqlWalBackupExecution;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'constants.coolify.self_hosted' => true,
    ]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Carbon::setTestNow(Carbon::parse('2026-07-24 12:00:00', 'UTC'));

    $this->team = Team::factory()->create();
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
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->sourceDatabase = createPostgresqlWalRestoreDatabase($environment, $this->destination);
    $this->storage = createPostgresqlWalRestoreStorage($this->team);
    $this->sourceConfiguration = PostgresqlWalBackupConfiguration::create([
        'team_id' => $this->team->id,
        'standalone_postgresql_id' => $this->sourceDatabase->id,
        's3_storage_id' => $this->storage->id,
        'postgres_major_version' => 16,
        'status' => 'healthy',
        'last_successful_base_backup_at' => now()->subHour(),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('uses the shared source repository lock and retries lock conflicts', function () {
    $job = new PostgresqlWalRestoreJob(
        $this->sourceConfiguration,
        CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC'),
        'restored-postgres',
    );
    $middleware = $job->middleware()[0];

    expect($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->shareKey)->toBeTrue()
        ->and($middleware->getLockKey($job))->toBe(
            'laravel-queue-overlap:'.PostgresqlWalBaseBackupJob::repositoryLockKey($this->sourceConfiguration->id),
        )
        ->and($middleware->expiresAfter)->toBeGreaterThan($job->timeout)
        ->and($middleware->releaseAfter)->toBe(30);
});

it('does not allow a retry to change the restore target time', function () {
    $execution = $this->sourceConfiguration->executions()->create([
        'operation' => 'restore',
        'target_time' => CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC'),
    ]);
    $job = new PostgresqlWalRestoreJob(
        $this->sourceConfiguration,
        CarbonImmutable::parse('2026-07-24 10:30:00', 'UTC'),
        'changed-target',
        executionUuid: $execution->uuid,
    );

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'cannot be changed');
});

it('rejects future restore targets before creating a target database', function () {
    Process::fake();
    $job = new PostgresqlWalRestoreJob(
        $this->sourceConfiguration,
        now()->addMinute(),
        'future-restore',
    );

    expect(fn () => $job->handle())->toThrow(ValidationException::class, 'future');

    $execution = PostgresqlWalBackupExecution::where('uuid', $job->executionUuid)->firstOrFail();
    expect($execution->status)->toBe('failed')
        ->and($execution->restored_database_id)->toBeNull()
        ->and(StandalonePostgresql::count())->toBe(1);
    Process::assertNothingRan();
});

it('restores from the source prefix, reconciles credentials, promotes, and disables archiving', function () {
    $sourceAttributes = $this->sourceDatabase->fresh()->getAttributes();
    $temporaryFilesBefore = glob(sys_get_temp_dir().'/coolify-pitr-credentials-*') ?: [];
    fakeSuccessfulPostgresqlWalRestoreProcesses();
    $job = new PostgresqlWalRestoreJob(
        $this->sourceConfiguration,
        CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC'),
        'restored-postgres',
        'Restored by PITR',
    );

    $job->handle();

    $execution = PostgresqlWalBackupExecution::where('uuid', $job->executionUuid)->firstOrFail();
    $target = $execution->restoredDatabase()->firstOrFail();
    $targetConfiguration = $target->walBackupConfiguration()->firstOrFail();
    expect($execution->status)->toBe('success')
        ->and($execution->backup_name)->toBe('base_restore_candidate')
        ->and($target->id)->not->toBe($this->sourceDatabase->id)
        ->and($target->name)->toBe('restored-postgres')
        ->and($target->description)->toBe('Restored by PITR')
        ->and($target->postgres_conf)->toContain("listen_addresses = '*'")
        ->and($targetConfiguration->enabled)->toBeFalse()
        ->and($targetConfiguration->status)->toBe('disabled')
        ->and($this->sourceDatabase->fresh()->getAttributes())->toBe($sourceAttributes)
        ->and($temporaryFilesBefore)->toBe(glob(sys_get_temp_dir().'/coolify-pitr-credentials-*') ?: []);

    Process::assertRan(fn ($process) => str_contains($process->command, 'backup-list --json --detail')
        && str_contains($process->command, '. /etc/wal-g/env'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'backup-fetch')
        && str_contains($process->command, '--network '.escapeshellarg($this->destination->network))
        && str_contains($process->command, '--user 999:999')
        && str_contains($process->command, 'base_restore_candidate'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'recovery.signal'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker cp')
        && str_contains($process->command, 'coolify-restore-credentials.sql'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'archive_mode'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, $target->postgres_password)
        || str_contains($process->command, 'restore-secret'));
});

it('forces and waits for a WAL switch before restoring a recent target', function () {
    fakeSuccessfulPostgresqlWalRestoreProcesses();
    $job = new PostgresqlWalRestoreJob(
        $this->sourceConfiguration,
        now()->subMinute(),
        'recent-restore',
    );

    $job->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, 'pg_switch_wal()')
        && str_contains($process->command, 'pg_stat_archiver')
        && str_contains($process->command, 'not archived in time'));
});

it('reuses one execution and target database when a queued restore attempt is retried', function () {
    fakeFailingPostgresqlWalFetchProcesses();
    $firstAttempt = new PostgresqlWalRestoreJob(
        $this->sourceConfiguration,
        CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC'),
        'retry-restore',
    );

    expect(fn () => $firstAttempt->handle())->toThrow(RuntimeException::class, 'fetch failed');

    $executionAfterFailure = PostgresqlWalBackupExecution::where('uuid', $firstAttempt->executionUuid)->firstOrFail();
    $targetId = $executionAfterFailure->restored_database_id;
    expect($executionAfterFailure->status)->toBe('running')
        ->and($targetId)->not->toBeNull()
        ->and(StandalonePostgresql::count())->toBe(2);
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, '/wal-g/env'));

    fakeSuccessfulPostgresqlWalRestoreProcesses();
    $retry = new PostgresqlWalRestoreJob(
        $this->sourceConfiguration,
        CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC'),
        'retry-restore',
        executionUuid: $firstAttempt->executionUuid,
    );
    $retry->handle();

    $executionAfterRetry = PostgresqlWalBackupExecution::where('uuid', $firstAttempt->executionUuid)->firstOrFail();
    expect($executionAfterRetry->id)->toBe($executionAfterFailure->id)
        ->and($executionAfterRetry->restored_database_id)->toBe($targetId)
        ->and($executionAfterRetry->status)->toBe('success')
        ->and(StandalonePostgresql::count())->toBe(2);
});

it('removes a partial target and preserves the source restore execution after final failure', function () {
    fakeFailingPostgresqlWalFetchProcesses();
    $job = new PostgresqlWalRestoreJob(
        $this->sourceConfiguration,
        CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC'),
        'failed-restore',
    );

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'fetch failed');
    $execution = PostgresqlWalBackupExecution::where('uuid', $job->executionUuid)->firstOrFail();
    $targetId = $execution->restored_database_id;

    Process::swap(new ProcessFactory);
    Process::fake(['*' => new FakeProcessResult]);
    $job->failed(new RuntimeException('final restore failure'));

    $execution->refresh();
    expect($execution->status)->toBe('failed')
        ->and($execution->message)->toBe('final restore failure')
        ->and($execution->restored_database_id)->toBeNull()
        ->and(StandalonePostgresql::find($targetId))->toBeNull()
        ->and(PostgresqlWalBackupConfiguration::where('standalone_postgresql_id', $targetId)->exists())->toBeFalse()
        ->and($this->sourceDatabase->fresh())->not->toBeNull();
});

function fakeSuccessfulPostgresqlWalRestoreProcesses(): void
{
    Process::swap(new ProcessFactory);
    Process::fake([
        '*backup-list --json --detail*' => new FakeProcessResult(output: json_encode([
            [
                'backup_name' => 'base_restore_candidate',
                'start_time' => '2026-07-24 08:00:00+00',
                'finish_time' => '2026-07-24 09:00:00+00',
            ],
        ], JSON_THROW_ON_ERROR)),
        '*current_setting*archive_mode*' => new FakeProcessResult(output: "off|f\n"),
        '*' => new FakeProcessResult,
    ]);
}

function fakeFailingPostgresqlWalFetchProcesses(): void
{
    Process::swap(new ProcessFactory);
    Process::fake([
        '*backup-list --json --detail*' => new FakeProcessResult(output: json_encode([
            [
                'backup_name' => 'base_restore_candidate',
                'start_time' => '2026-07-24 08:00:00+00',
                'finish_time' => '2026-07-24 09:00:00+00',
            ],
        ], JSON_THROW_ON_ERROR)),
        '*backup-fetch*' => Process::result(errorOutput: 'fetch failed', exitCode: 1),
        '*' => new FakeProcessResult,
    ]);
}

function createPostgresqlWalRestoreDatabase(
    Environment $environment,
    StandaloneDocker $destination,
): StandalonePostgresql {
    return StandalonePostgresql::create([
        'name' => 'source-postgres',
        'image' => 'ghcr.io/coollabsio/postgres-walg:16',
        'postgres_user' => 'postgres',
        'postgres_password' => 'source-password',
        'postgres_db' => 'postgres',
        'status' => 'running:healthy',
        'is_public' => false,
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createPostgresqlWalRestoreStorage(Team $team): S3Storage
{
    return S3Storage::create([
        'name' => 'Restore WAL archive',
        'region' => 'us-east-1',
        'key' => 'restore-key',
        'secret' => 'restore-secret',
        'bucket' => 'restore-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}
