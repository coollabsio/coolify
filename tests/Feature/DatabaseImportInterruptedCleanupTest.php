<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDatabaseImport;
use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Events\DatabaseImportFinished;
use App\Jobs\CoolifyTask;
use App\Listeners\CleanupDatabaseImport;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Support\DatabaseImport\DatabaseImportCleanup;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\RemoteProcessCommand;
use App\Support\ResourceStartActivity;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'root',
    ]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Process::fake();
    Queue::fake();
});

afterEach(function () {
    Server::flushIdentityMap();
});

/**
 * @return array<string, mixed>
 */
function storedImportCleanup(object $test, string $operation): array
{
    return [
        'container' => $test->database->uuid,
        'containerTmpPath' => "/tmp/restore_{$operation}",
        'scriptPath' => "/tmp/restore_{$operation}.sh",
        'serverId' => $test->server->id,
        'operationUuid' => $operation,
        'containerName' => "s3-restore-{$operation}",
    ];
}

function runningImportActivity(object $test, ProcessStatus $status, ?array $cleanup, int $minutesSinceLastUpdate = 0): Activity
{
    $activity = activity()
        ->withProperties(array_filter([
            'type' => ActivityTypes::INLINE->value,
            'type_uuid' => $test->database->uuid,
            'team_id' => $test->team->id,
            'status' => $status->value,
            'operation' => ResourceStartActivity::DATABASE_IMPORT_OPERATION,
            'operation_uuid' => $cleanup['operationUuid'] ?? null,
            DatabaseImportCleanup::PROPERTY => $cleanup,
        ], fn ($value) => $value !== null))
        ->event(ActivityTypes::INLINE->value)
        ->log('[]');

    Activity::query()->whereKey($activity->id)->update([
        'created_at' => now()->subMinutes($minutesSinceLastUpdate),
        'updated_at' => now()->subMinutes($minutesSinceLastUpdate),
    ]);

    return $activity->refresh();
}

function assertStopQueuedOnce(array $expected): void
{
    Queue::assertPushed(CallQueuedListener::class, 1);
    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) use ($expected) {
        $event = $job->data[0] ?? null;

        return $job->class === CleanupDatabaseImport::class
            && $event instanceof DatabaseImportFinished
            && $event->data === [...$expected, 'stopRestore' => true];
    });
}

test('an interrupted import queues the stop and cleanup with the stored data once, without SSH at boot', function () {
    $operation = (string) Str::uuid();
    $cleanup = storedImportCleanup($this, $operation);
    $activity = runningImportActivity($this, ProcessStatus::IN_PROGRESS, $cleanup);

    expect(ResourceStartActivity::failInterrupted())->toBe(1)
        ->and(ResourceStartActivity::failInterrupted())->toBe(0);

    assertStopQueuedOnce($cleanup);
    Process::assertNothingRan();

    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toBe(ResourceStartActivity::INTERRUPTED_MESSAGE.' '.ResourceStartActivity::IMPORT_STOPPED_MESSAGE)
        ->and(data_get($activity, 'properties.'.DatabaseImportCleanup::STOP_REQUESTED_PROPERTY))->not->toBeNull()
        ->and($activity->description)->toContain('The import was stopped. The database may be partly restored.');
});

test('a stale import queues the stop and cleanup with the stored data once', function () {
    $operation = (string) Str::uuid();
    $cleanup = storedImportCleanup($this, $operation);
    $minutes = intdiv(ResourceStartActivity::importStaleAfterSeconds(), 60) + 5;
    $stale = runningImportActivity($this, ProcessStatus::IN_PROGRESS, $cleanup, $minutes);

    foreach ([1, 2] as $attempt) {
        expect(fn () => app(StartDatabaseImport::class)->handle(
            $this->database,
            new DatabaseImportSource('server', path: 'backup.sql'),
            $this->team->id,
        ))->toThrow(DatabaseImportException::class, 'The server path is invalid.');
    }

    assertStopQueuedOnce($cleanup);
    Process::assertNothingRan();

    $stale->refresh();
    expect(data_get($stale, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($stale, 'properties.error'))->toBe(ResourceStartActivity::STALE_MESSAGE.' '.ResourceStartActivity::IMPORT_STOPPED_MESSAGE);
});

test('an import without stored cleanup data is failed without a stop', function () {
    $activity = runningImportActivity($this, ProcessStatus::QUEUED, null);

    expect(ResourceStartActivity::failInterrupted())->toBe(1);

    Queue::assertNotPushed(CallQueuedListener::class);
    expect(data_get($activity->refresh(), 'properties.error'))
        ->toBe(ResourceStartActivity::INTERRUPTED_MESSAGE.' '.ResourceStartActivity::IMPORT_PARTLY_RESTORED_MESSAGE)
        ->and(data_get($activity, 'properties.'.DatabaseImportCleanup::STOP_REQUESTED_PROPERTY))->toBeNull();
});

test('stored cleanup data with unexpected keys or an invalid operation is not used', function () {
    $operation = (string) Str::uuid();
    $cleanup = [...storedImportCleanup($this, $operation), 'secret' => 'do-not-send'];
    runningImportActivity($this, ProcessStatus::IN_PROGRESS, $cleanup);
    runningImportActivity($this, ProcessStatus::IN_PROGRESS, [...storedImportCleanup($this, $operation), 'operationUuid' => "x'; reboot; '"]);

    expect(ResourceStartActivity::failInterrupted())->toBe(2);

    assertStopQueuedOnce(storedImportCleanup($this, $operation));
});

test('a stopped import is not run again when its queued task is retried after a restart', function () {
    $operation = (string) Str::uuid();
    $activity = runningImportActivity($this, ProcessStatus::IN_PROGRESS, storedImportCleanup($this, $operation));
    ResourceStartActivity::failInterrupted();

    $job = new CoolifyTask($activity->refresh(), ignore_errors: false, call_event_on_finish: 'DatabaseImportFinished', call_event_data: storedImportCleanup($this, $operation));
    $job->handle();

    Process::assertNothingRan();
    expect(data_get($activity->refresh(), 'properties.status'))->toBe(ProcessStatus::ERROR->value);
    Queue::assertPushed(CallQueuedListener::class, 1);
});

test('the cleanup runs at most once per import', function () {
    $operation = (string) Str::uuid();
    $cleanup = storedImportCleanup($this, $operation);
    $listener = new CleanupDatabaseImport;

    $listener->handle(new DatabaseImportFinished([...$cleanup, 'stopRestore' => true]));
    $listener->handle(new DatabaseImportFinished($cleanup));
    $listener->handle(new DatabaseImportFinished([...$cleanup, 'stopRestore' => true]));

    Process::assertRanTimes(fn ($process) => str_contains($process->command, "s3-restore-{$operation}"), 1);
    Process::assertRan(fn ($process) => str_contains($process->command, "/tmp/restore_{$operation}")
        && str_contains($process->command, 'Stopping the database import processes'));
});

test('the cleanup of an import without an operation id still runs every time', function () {
    $cleanup = storedImportCleanup($this, (string) Str::uuid());
    unset($cleanup['operationUuid']);
    $listener = new CleanupDatabaseImport;

    $listener->handle(new DatabaseImportFinished($cleanup));
    $listener->handle(new DatabaseImportFinished($cleanup));

    Process::assertRanTimes(fn ($process) => str_contains($process->command, $cleanup['containerName']), 2);
});

it('keeps the stop message when the stopped import process ends afterwards', function () {
    $operation = (string) Str::uuid();
    $activity = runningImportActivity($this, ProcessStatus::IN_PROGRESS, storedImportCleanup($this, $operation));
    $activity->properties = $activity->properties->merge([
        'server_uuid' => $this->server->uuid,
        'command' => "docker exec {$this->database->uuid} sh -c /tmp/restore_{$operation}.sh",
    ]);
    $activity->save();

    // The running job holds its own copy of the activity while the stale rule stops the import.
    $jobCopy = Activity::query()->findOrFail($activity->id);
    $remoteProcess = new RunRemoteProcess(activity: $jobCopy, ignore_errors: true);

    // The stale rule stops the import while the process runs; the killed restore then prints output.
    Process::fake(function () use ($activity) {
        $stopped = Activity::query()->findOrFail($activity->id);
        $stopped->properties = $stopped->properties->merge([
            'status' => ProcessStatus::ERROR->value,
            'error' => 'Stale import. The import was stopped. The database may be partly restored.',
            DatabaseImportCleanup::STOP_REQUESTED_PROPERTY => now()->toIso8601String(),
        ]);
        $stopped->save();

        return Process::result(errorOutput: "Terminated\n", exitCode: 143);
    });
    $remoteProcess();

    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toContain('The import was stopped')
        ->and(DatabaseImportCleanup::stopRequested($activity))->toBeTrue()
        ->and(data_get($activity, 'properties.exitCode'))->toBe(143)
        ->and(RunRemoteProcess::decodeOutput($activity))->toContain('Terminated');
});

it('keeps the stop message when the stopped import job fails', function () {
    $operation = (string) Str::uuid();
    $activity = runningImportActivity($this, ProcessStatus::ERROR, storedImportCleanup($this, $operation));
    $activity->properties = $activity->properties->merge([
        'error' => 'Stale import. The import was stopped. The database may be partly restored.',
        DatabaseImportCleanup::STOP_REQUESTED_PROPERTY => now()->toIso8601String(),
    ]);
    $activity->save();

    $job = new CoolifyTask(activity: $activity->fresh(), ignore_errors: false, call_event_on_finish: null, call_event_data: null);
    $job->failed(new RuntimeException("Terminated\n"));

    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toContain('The import was stopped')
        ->and(data_get($activity, 'properties.failed_at'))->not->toBeNull();
});

test('an import task may run longer than the SSH command timeout while other remote tasks keep the default timeout', function (int $commandTimeout) {
    config()->set('constants.ssh.command_timeout', $commandTimeout);
    Process::swap(new ProcessFactory);
    Process::fake([
        '*stat -c %s*' => Process::result('2048'),
        '*od -An -tx1*' => Process::result(' 2d 2d 20 50 6f 73'),
        '*' => Process::result(''),
    ]);

    app(StartDatabaseImport::class)->handle($this->database, new DatabaseImportSource('server', path: '/srv/backups/app.sql'), $this->team->id);
    remote_process(['echo ok'], $this->server);

    $timeouts = Queue::pushed(CoolifyTask::class)->mapWithKeys(fn (CoolifyTask $job) => [
        (data_get($job->activity, 'properties.operation') ?? 'other') => $job->timeout,
    ]);
    $workerTimeout = (int) config('horizon.worker_timeout');

    expect($timeouts['other'])->toBe(600)
        ->and($timeouts[ResourceStartActivity::DATABASE_IMPORT_OPERATION])->toBeGreaterThan(min($commandTimeout, $workerTimeout - 600))
        ->and($timeouts[ResourceStartActivity::DATABASE_IMPORT_OPERATION])->toBeLessThan($workerTimeout)
        ->and($workerTimeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
})->with([
    'default SSH command timeout' => 3600,
    'SSH command timeout above the Horizon worker timeout' => 200000,
]);

test('a failed import task stops the remote restore and blocks the database until the restore is stopped', function () {
    $operation = (string) Str::uuid();
    $cleanup = storedImportCleanup($this, $operation);
    $activity = runningImportActivity($this, ProcessStatus::IN_PROGRESS, $cleanup);
    $job = new CoolifyTask($activity, ignore_errors: false, call_event_on_finish: 'DatabaseImportFinished', call_event_data: $cleanup);

    $job->failed(new RuntimeException('App\\Jobs\\CoolifyTask has timed out.'));

    assertStopQueuedOnce($cleanup);
    expect(data_get($activity->refresh(), 'properties.status'))->toBe(ProcessStatus::IN_PROGRESS->value)
        ->and(StartDatabase::operationInProgressError($this->database))->toBe(ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);

    (new CleanupDatabaseImport)->handle(new DatabaseImportFinished([...$cleanup, 'stopRestore' => true]));

    Process::assertRan(fn ($process) => str_contains($process->command, "/tmp/restore_{$operation}")
        && str_contains($process->command, 'Stopping the database import processes'));
    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toBe('App\\Jobs\\CoolifyTask has timed out. '.ResourceStartActivity::IMPORT_STOPPED_MESSAGE)
        ->and(StartDatabase::operationInProgressError($this->database))->toBeNull();
});

test('a failed import task whose restore already ended is only cleaned up', function () {
    $operation = (string) Str::uuid();
    $cleanup = storedImportCleanup($this, $operation);
    $activity = runningImportActivity($this, ProcessStatus::ERROR, $cleanup);
    $job = new CoolifyTask($activity, ignore_errors: false, call_event_on_finish: 'DatabaseImportFinished', call_event_data: $cleanup);

    $job->failed(new RuntimeException('psql: error'));

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $listener) => $listener->data[0]->data === $cleanup);
    expect(data_get($activity->refresh(), 'properties.error'))->toBe('psql: error')
        ->and(DatabaseImportCleanup::stopRequested($activity))->toBeFalse();
});

/**
 * A CoolifyTask of a running import whose SSH command ends with the given result.
 *
 * @return array{0: Activity, 1: CoolifyTask, 2: array<string, mixed>}
 */
function importTaskEndingWith(object $test, int $exitCode, string $errorOutput): array
{
    $operation = (string) Str::uuid();
    $cleanup = storedImportCleanup($test, $operation);
    $activity = runningImportActivity($test, ProcessStatus::IN_PROGRESS, $cleanup);
    $activity->properties = $activity->properties->merge([
        'server_uuid' => $test->server->uuid,
        'command' => "docker exec {$test->database->uuid} sh -c /tmp/restore_{$operation}.sh",
    ]);
    $activity->save();

    Process::fake(fn () => Process::result(errorOutput: $errorOutput, exitCode: $exitCode));

    return [$activity, new CoolifyTask($activity->fresh(), ignore_errors: false, call_event_on_finish: 'DatabaseImportFinished', call_event_data: $cleanup), $cleanup];
}

test('an import whose SSH command ends without the restore result stops the restore and blocks the database until it is stopped', function (int $exitCode, string $reason) {
    [$activity, $job, $cleanup] = importTaskEndingWith($this, $exitCode, "Connection to 10.0.0.1 closed by remote host.\n");

    // The queue calls failed() after the exception, because the task allows one exception.
    try {
        $job->handle();
        $this->fail('The task must fail.');
    } catch (RuntimeException $exception) {
        $job->failed($exception);
    }

    // Only the stop is queued; the normal cleanup without the stop must not run first.
    assertStopQueuedOnce($cleanup);
    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::IN_PROGRESS->value)
        ->and(data_get($activity, 'properties.exitCode'))->toBe($exitCode)
        ->and(StartDatabase::operationInProgressError($this->database))->toBe(ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);

    // The server is reachable again when the queued stop runs.
    Process::swap(new ProcessFactory);
    Process::fake();
    (new CleanupDatabaseImport)->handle(new DatabaseImportFinished([...$cleanup, 'stopRestore' => true]));

    Process::assertRan(fn ($process) => str_contains($process->command, 'Stopping the database import processes'));
    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toBe("{$reason} ".ResourceStartActivity::IMPORT_STOPPED_MESSAGE)
        ->and(RunRemoteProcess::decodeOutput($activity))->toContain($reason)
        ->and(StartDatabase::operationInProgressError($this->database))->toBeNull();
})->with([
    'SSH connection lost (ssh exit 255)' => [255, 'The connection to the server was lost.'],
    'local SSH command timeout (timeout exit 124)' => [124, 'The import timed out.'],
]);

test('an import whose restore fails by itself is failed and cleaned up without a stop', function () {
    [$activity, $job, $cleanup] = importTaskEndingWith($this, 3, "psql: ERROR: syntax error\n");

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    Queue::assertPushed(CallQueuedListener::class, 1);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $listener) => $listener->data[0]->data === $cleanup);
    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(DatabaseImportCleanup::stopRequested($activity))->toBeFalse()
        ->and(StartDatabase::operationInProgressError($this->database))->toBeNull();
});

test('a lost import whose stop also fails stays blocking until the stale rule stops it again', function () {
    config()->set('constants.ssh.max_retries', 1);
    [$activity, $job, $cleanup] = importTaskEndingWith($this, 255, "Connection to 10.0.0.1 closed by remote host.\n");
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    // The server is still unreachable, so the stop fails and the listener releases its claim for a retry.
    expect(fn () => (new CleanupDatabaseImport)->handle(new DatabaseImportFinished([...$cleanup, 'stopRestore' => true])))
        ->toThrow(RuntimeException::class);
    expect(data_get($activity->refresh(), 'properties.status'))->toBe(ProcessStatus::IN_PROGRESS->value)
        ->and(StartDatabase::operationInProgressError($this->database))->toBe(ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);

    $this->travel(ResourceStartActivity::importStaleAfterSeconds() + 60)->seconds();
    Queue::fake();

    expect(StartDatabase::operationInProgressError($this->database))->toBeNull();
    assertStopQueuedOnce($cleanup);
    expect(data_get($activity->refresh(), 'properties.error'))->toBe(ResourceStartActivity::STALE_MESSAGE.' '.ResourceStartActivity::IMPORT_STOPPED_MESSAGE);
});

test('a restore that exits like a lost SSH connection or timeout reports a normal failure, also for a non-root SSH user', function (string $user, int $restoreExitCode, int $expectedExitCode) {
    $this->server->update(['user' => $user]);
    Server::flushIdentityMap();
    Process::swap(new ProcessFactory);
    Process::fake([
        '*stat -c %s*' => Process::result('2048'),
        '*od -An -tx1*' => Process::result(' 2d 2d 20 50 6f 73'),
        '*' => Process::result(''),
    ]);

    app(StartDatabaseImport::class)->handle($this->database->fresh(), new DatabaseImportSource('server', path: '/srv/backups/app.sql'), $this->team->id);

    $task = Queue::pushed(CoolifyTask::class)->first();
    $restoreLine = collect(explode("\n", RemoteProcessCommand::read($task->activity)))->last();
    $scriptPath = data_get($task->activity, 'properties.'.DatabaseImportCleanup::PROPERTY.'.scriptPath');
    expect($restoreLine)->toContain($scriptPath);

    // Fake sudo and docker: `docker exec <container> ...` runs the restore script on this machine.
    $dir = sys_get_temp_dir().'/coolify-restore-exit-'.Str::random(8);
    mkdir($dir);
    file_put_contents("{$dir}/sudo", "#!/bin/sh\nexec \"\$@\"\n");
    file_put_contents("{$dir}/docker", "#!/bin/sh\n[ \"\$1\" = exec ] || exit 1\nshift 2\nexec \"\$@\"\n");
    file_put_contents($scriptPath, "echo restoring\nexit {$restoreExitCode}\n");
    chmod("{$dir}/sudo", 0755);
    chmod("{$dir}/docker", 0755);
    chmod($scriptPath, 0755);

    try {
        $restore = new Symfony\Component\Process\Process(['bash', '-c', $restoreLine], env: ['PATH' => "{$dir}:".getenv('PATH')]);
        $restore->run();

        expect($restore->getExitCode())->toBe($expectedExitCode)
            ->and($restore->getOutput())->toContain('restoring');
    } finally {
        @unlink($scriptPath);
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }
})->with([
    'root, restore exits 255' => ['root', 255, 1],
    'root, restore exits 124' => ['root', 124, 1],
    'root, restore fails' => ['root', 3, 3],
    'root, restore succeeds' => ['root', 0, 0],
    'non-root, restore exits 255' => ['ubuntu', 255, 1],
    'non-root, restore fails' => ['ubuntu', 3, 3],
])->skip(fn () => PHP_OS_FAMILY !== 'Linux', 'Needs a POSIX shell.');

test('a normal remote task that loses its SSH connection fails as before without an import stop', function (int $exitCode) {
    Queue::fake();
    Process::fake(fn () => Process::result(errorOutput: "Connection to 10.0.0.1 closed by remote host.\n", exitCode: $exitCode));
    $activity = remote_process(['docker ps'], $this->server, ignore_errors: true, callEventOnFinish: 'DatabaseImportFinished', callEventData: ['marker' => 'normal-task']);

    (new CoolifyTask($activity->fresh(), ignore_errors: true, call_event_on_finish: 'DatabaseImportFinished', call_event_data: ['marker' => 'normal-task']))->handle();

    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.exitCode'))->toBe($exitCode)
        ->and(data_get($activity, 'properties.'.DatabaseImportCleanup::STOP_REQUESTED_PROPERTY))->toBeNull();
    // The normal finish event runs, without a stop.
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => ($job->data[0] ?? null) instanceof DatabaseImportFinished
        && $job->data[0]->data === ['marker' => 'normal-task']);
})->with(['ssh exit 255' => 255, 'timeout exit 124' => 124]);
