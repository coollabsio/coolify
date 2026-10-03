<?php

use App\Enums\ProcessStatus;
use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledJobState;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ScheduledJobDeliveryService;
use App\Support\DatabaseOperationReservation;
use App\Support\ResourceStartActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Event::fake([BackupCreated::class]);
    Notification::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * @return Collection<int, string> The remote commands the job ran.
 */
function backupRunSafetyFakeRemoteCommands(array $outputs = []): Collection
{
    $commands = collect();
    Process::fake(function ($process) use ($commands, $outputs) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands->push($command);
        foreach ($outputs as $needle => $output) {
            if (str_contains($command, $needle)) {
                return Process::result(output: $output);
            }
        }

        return Process::result(output: str_contains($command, 'du -b') ? '128' : '');
    });

    return $commands;
}

function backupRunSafetySchedule(Model $database, Team $team, array $overrides = []): ScheduledDatabaseBackup
{
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => 'daily',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        ...$overrides,
    ]);
    $backup->save();

    return $backup->fresh();
}

function backupRunSafetyOccurrence(string $scheduleKey, string $jobType, int $resourceId): ScheduledJobDelivery
{
    return ScheduledJobDelivery::create([
        'schedule_key' => $scheduleKey,
        'scheduled_for' => now()->startOfMinute(),
        'job_type' => $jobType,
        'resource_id' => $resourceId,
        'status' => 'enqueued',
        'enqueued_at' => now(),
    ]);
}

function backupRunSafetyActivity(string $typeUuid, string $operation, ProcessStatus $status): Activity
{
    return Activity::create([
        'log_name' => 'default',
        'description' => '[]',
        'properties' => [
            'type_uuid' => $typeUuid,
            'operation' => $operation,
            'status' => $status->value,
        ],
    ]);
}

// --- Overlapped occurrences (M5) ---

it('skips a database backup occurrence when the previous run still holds the overlap lock, so recovery does not run it later', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['status' => 'running:healthy']);
    $backup = backupRunSafetySchedule($database, $this->team);
    $occurrence = backupRunSafetyOccurrence("scheduled-backup:{$backup->id}", 'database-backup', $backup->id);
    $job = new DatabaseBackupJob($backup, $occurrence->uuid);
    $runningBackupLock = Cache::lock($job->middleware()[0]->getLockKey($job), 3600);
    $runningBackupLock->get();
    $commands = backupRunSafetyFakeRemoteCommands();

    dispatch_sync($job);
    $runningBackupLock->release();

    expect($occurrence->fresh()->status)->toBe('skipped')
        ->and(ScheduledDatabaseBackupExecution::query()->exists())->toBeFalse()
        ->and($commands)->toBeEmpty();

    Carbon::setTestNow(now()->addMinutes(ScheduledJobDeliveryService::ENQUEUED_STALE_AFTER_MINUTES + 1));
    Queue::fake();
    app(ScheduledJobDeliveryService::class)->recoverStaleEnqueued();

    Queue::assertNothingPushed();
});

it('skips a volume backup occurrence when the previous run still holds the overlap lock', function () {
    $volume = backupRunSafetyVolume($this->environment, $this->destination);
    $backup = $volume->scheduledBackups()->create(['team_id' => $this->team->id, 'frequency' => 'daily', 'enabled' => true]);
    $occurrence = backupRunSafetyOccurrence("scheduled-volume-backup:{$backup->id}", 'volume-backup', $backup->id);
    $runningBackupLock = Cache::lock(VolumeBackupJob::lockKey($backup->id), 3600);
    $runningBackupLock->get();
    $commands = backupRunSafetyFakeRemoteCommands();

    dispatch_sync(new VolumeBackupJob($backup, $occurrence->uuid));
    $runningBackupLock->release();

    expect($occurrence->fresh()->status)->toBe('skipped')
        ->and(ScheduledVolumeBackupExecution::query()->exists())->toBeFalse()
        ->and($commands)->toBeEmpty();
});

it('does not run a lost backup occurrence again when a newer occurrence already completed', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['status' => 'running:healthy']);
    $backup = backupRunSafetySchedule($database, $this->team, ['frequency' => 'hourly']);
    $scheduleKey = "scheduled-backup:{$backup->id}";
    $lost = ScheduledJobDelivery::create([
        'schedule_key' => $scheduleKey,
        'scheduled_for' => Carbon::create(2026, 9, 17, 10, 0, 0, 'UTC'),
        'job_type' => 'database-backup',
        'resource_id' => $backup->id,
        'status' => 'enqueued',
        'enqueued_at' => now()->subMinutes(ScheduledJobDeliveryService::ENQUEUED_STALE_AFTER_MINUTES + 1),
    ]);
    // The 11:00 occurrence ran and completed; complete() deleted its row.
    ScheduledJobState::create(['schedule_key' => $scheduleKey, 'last_scheduled_for' => Carbon::create(2026, 9, 17, 11, 0, 0, 'UTC')]);

    app(ScheduledJobDeliveryService::class)->recoverStaleEnqueued();

    Queue::assertNotPushed(DatabaseBackupJob::class);
    expect($lost->fresh()->status)->toBe('failed');
});

// --- Imports and starts (M10) ---

it('does not back up a database while a start or import of it is in progress', function (Closure $startOperation) {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['status' => 'running:healthy']);
    $backup = backupRunSafetySchedule($database, $this->team);
    $occurrence = backupRunSafetyOccurrence("scheduled-backup:{$backup->id}", 'database-backup', $backup->id);
    $startOperation($database);
    $commands = backupRunSafetyFakeRemoteCommands();

    (new DatabaseBackupJob($backup, $occurrence->uuid))->handle();

    $execution = ScheduledDatabaseBackupExecution::query()->sole();
    expect($commands->filter(fn (string $command) => str_contains($command, 'pg_dump')))->toBeEmpty()
        ->and($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('Skipped')
        ->and($execution->filename)->toBeNull()
        ->and($execution->finished_at)->not->toBeNull()
        ->and(ScheduledJobDelivery::query()->whereKey($occurrence->id)->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with([
    'reserved import, start or restart' => [fn (Model $database) => DatabaseOperationReservation::acquire($database->uuid)],
    'running import' => [fn (Model $database) => backupRunSafetyActivity($database->uuid, ResourceStartActivity::DATABASE_IMPORT_OPERATION, ProcessStatus::IN_PROGRESS)],
    'queued start' => [fn (Model $database) => backupRunSafetyActivity($database->uuid, ResourceStartActivity::DATABASE_START_OPERATION, ProcessStatus::QUEUED)],
]);

it('backs up a database when its last import is stale', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['status' => 'running:healthy']);
    $backup = backupRunSafetySchedule($database, $this->team);
    $import = backupRunSafetyActivity($database->uuid, ResourceStartActivity::DATABASE_IMPORT_OPERATION, ProcessStatus::IN_PROGRESS);
    Activity::query()->whereKey($import->id)->update(['updated_at' => now()->subSeconds(ResourceStartActivity::importStaleAfterSeconds() + 60)]);
    $commands = backupRunSafetyFakeRemoteCommands();

    (new DatabaseBackupJob($backup))->handle();

    expect($commands->filter(fn (string $command) => str_contains($command, 'pg_dump')))->not->toBeEmpty()
        ->and(ScheduledDatabaseBackupExecution::query()->sole()->status)->toBe('success');
});

// --- Unique backup file names (M11) ---

it('gives each backup run of the same database its own file in the same second', function (Closure $createDatabase, array $backupSettings, string $extension) {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));
    $database = $createDatabase($this->environment->id, $this->destination, ['status' => 'running:healthy']);
    $first = backupRunSafetySchedule($database, $this->team, $backupSettings);
    $second = backupRunSafetySchedule($database, $this->team, $backupSettings);
    backupRunSafetyFakeRemoteCommands();

    (new DatabaseBackupJob($first))->handle();
    (new DatabaseBackupJob($second))->handle();

    $executions = ScheduledDatabaseBackupExecution::query()->orderBy('id')->get();
    expect($executions)->toHaveCount(2)
        ->and($executions->pluck('status')->unique()->all())->toBe(['success'])
        ->and($executions->pluck('filename')->unique())->toHaveCount(2);
    foreach ($executions as $execution) {
        expect($execution->filename)->toEndWith("-{$execution->uuid}{$extension}");
    }
})->with([
    'postgresql' => [fn (...$arguments) => create_standalone_postgresql(...$arguments), [], '.dmp'],
    'postgresql full dump' => [fn (...$arguments) => create_standalone_postgresql(...$arguments), ['dump_all' => true], '.gz'],
    'mysql' => [fn (...$arguments) => create_standalone_mysql(...$arguments), [], '.dmp'],
    'mariadb' => [fn (...$arguments) => create_standalone_mariadb(...$arguments), [], '.dmp'],
    'mongodb' => [fn (...$arguments) => create_standalone_mongodb(...$arguments), [], '.tar.gz'],
    'sqlite' => [fn (...$arguments) => create_standalone_sqlite(...$arguments), [], '.gz'],
]);

// --- Service database backup folder (M12) ---

it('stores service database backups in the folder of earlier releases', function () {
    $service = Service::factory()->create([
        'name' => 'Rallly',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    $database = ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'rallly_db',
        'image' => 'postgres:16-alpine',
        'status' => 'running:healthy',
    ]);
    $backup = backupRunSafetySchedule($database, $this->team);
    backupRunSafetyFakeRemoteCommands(['env | grep POSTGRES_' => "POSTGRES_USER=rallly\nPOSTGRES_DB=rallly"]);

    (new DatabaseBackupJob($backup))->handle();

    expect(ScheduledDatabaseBackupExecution::query()->sole()->filename)
        ->toContain("/rallly-rallly_db-{$service->uuid}/pg-dump-rallly-");
});

function backupRunSafetyVolume(Environment $environment, StandaloneDocker $destination): LocalPersistentVolume
{
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    return LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
}
