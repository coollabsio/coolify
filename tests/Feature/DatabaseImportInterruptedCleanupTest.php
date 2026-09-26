<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
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
use App\Support\ResourceStartActivity;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
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
