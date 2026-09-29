<?php

use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\ScheduledJobDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config(['constants.coolify.self_hosted' => true]);
    Queue::fake();
});

it('dispatches due scheduled tasks across chunks and moves their next run forward', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 0, 30, 'UTC'));
    $application = createScheduledTaskApplication();
    foreach (range(1, 101) as $ignored) {
        createScheduledApplicationTask($application, ['frequency' => '* * * * *']);
    }

    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, 101);
    expect(ScheduledTask::query()->pluck('next_run_at')->map->toDateTimeString()->unique()->all())
        ->toBe(['2026-05-27 00:02:00']);
});

it('runs a new schedule first at its next due time', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 20, 'UTC'));
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => '* * * * *']);

    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-05-27 00:02:00');
});

it('does not query schedules that are not due', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 0, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $database = createScheduledBackupDatabase();
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new ScheduledJobManager)->handle();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    // Initialize the Docker cleanup schedules of both servers, so only the schedules below change.
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $countQueries();
    $withoutSchedules = $countQueries();

    foreach (range(1, 5) as $ignored) {
        createScheduledApplicationTask($application, ['frequency' => '0 2 * * *']);
        createScheduledDatabaseBackup($database, ['frequency' => '0 2 * * *']);
    }

    expect($countQueries())->toBe($withoutSchedules);
    Queue::assertNothingPushed();
});

it('dispatches one job when several managers run for the same occurrence', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 12, 0, 0, 'UTC'));
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 5, 'UTC'));
    (new ScheduledJobManager)->handle();
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, 1);
    expect(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-task:{$task->id}")->count())->toBe(1)
        ->and($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00');
});

it('does not dispatch an occurrence that another manager claims after this one read it', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 12, 0, 0, 'UTC'));
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 5, 'UTC'));

    // Another node claims the occurrence between this manager's select and its update.
    $claimedByOtherNode = false;
    ScheduledTask::retrieved(function (ScheduledTask $retrieved) use (&$claimedByOtherNode) {
        if (! $claimedByOtherNode) {
            $claimedByOtherNode = true;
            ScheduledTask::query()->whereKey($retrieved->id)->toBase()->update(['next_run_at' => '2026-09-18 00:00:00']);
        }
    });

    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect($claimedByOtherNode)->toBeTrue()
        ->and(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-task:{$task->id}")->exists())->toBeFalse();
});

it('calculates the next run of a schedule without one and runs it when it is due now', function (string $frequency, bool $runsNow, string $nextRunAt) {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 20, 'UTC'));
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => $frequency]);
    ScheduledTask::query()->whereKey($task->id)->update(['next_run_at' => null]);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, $runsNow ? 1 : 0);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe($nextRunAt);
})->with([
    'due in the current minute' => ['0 0 * * *', true, '2026-09-18 00:00:00'],
    'due later' => ['30 * * * *', false, '2026-09-17 00:30:00'],
]);

it('moves the next run forward when an occurrence is skipped, and does not retry it', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 12, 0, 0, 'UTC'));
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    $server = $task->server();
    $server->settings()->update(['is_reachable' => false]);

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 5, 'UTC'));
    (new ScheduledJobManager)->handle();
    $server->settings()->update(['is_reachable' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 1, 0, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00')
        ->and(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-task:{$task->id}")->exists())->toBeFalse();
});

it('does not dispatch a task when its application is not running', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 12, 0, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $application->update(['status' => 'stopped']);
    $task = createScheduledApplicationTask($application, ['frequency' => 'daily']);
    $logPath = captureScheduledJobManagerTestLog('scheduled');

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 5, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect(file_get_contents($logPath))->toContain('Task skipped')->toContain('"skip_reason":"application_not_running"');
    @unlink($logPath);
});

it('runs late backups once, but logs late tasks outside the late-run window as missed', function (int $minutesLate, bool $taskRuns) {
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 12, 0, 0, 'UTC'));
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'daily']);
    $logPath = captureScheduledJobManagerTestLog('scheduled');

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC')->addMinutes($minutesLate));
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, $taskRuns ? 1 : 0);
    Queue::assertPushed(DatabaseBackupJob::class, 1);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00')
        ->and($backup->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00')
        ->and(str_contains(file_get_contents($logPath), 'Task missed'))->toBe(! $taskRuns);
    @unlink($logPath);
})->with([
    'five minutes late' => [5, true],
    'three hours late' => [180, false],
]);

it('runs a daily schedule once on the night the clocks go back', function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 24, 12, 0, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $application->destination->server->settings->update(['server_timezone' => 'Europe/Budapest']);
    $task = createScheduledApplicationTask($application, ['frequency' => '30 2 * * *']);

    // 02:30 CEST. At 03:00 CEST the clocks go back to 02:00, so 02:30 CET follows one hour later.
    Carbon::setTestNow(Carbon::create(2026, 10, 25, 0, 30, 5, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, 1);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-10-26 01:30:00');
});

it('dispatches due Docker cleanups with their settings', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 30, 0, 'UTC'));
    $server = createScheduledTaskApplication()->destination->server;
    $server->settings->update(['docker_cleanup_frequency' => '0 * * * *', 'delete_unused_volumes' => true]);

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 1, 0, 10, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DockerCleanupJob::class, fn (DockerCleanupJob $job) => $job->server->is($server) && $job->deleteUnusedVolumes);
    expect($server->settings->fresh()->docker_cleanup_next_run_at->toDateTimeString())->toBe('2026-09-17 02:00:00');
});

it('does not dispatch an occurrence that already has a delivery', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 12, 0, 0, 'UTC'));
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    ScheduledJobDelivery::create([
        'schedule_key' => "scheduled-task:{$task->id}",
        'scheduled_for' => Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'),
        'job_type' => 'scheduled-task',
        'resource_id' => $task->id,
        'status' => 'claimed',
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 5, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00');
});

it('dispatches a due backup of a service database', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 4, 30, 'UTC'));
    $backup = createScheduledServiceDatabaseBackup(['frequency' => '* * * * *']);

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->backup->is($backup));
});

it('publishes a pending occurrence after a previous publisher interruption', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    $occurrence = ScheduledJobDelivery::create([
        'schedule_key' => "scheduled-task:{$task->id}",
        'scheduled_for' => Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'),
        'job_type' => 'scheduled-task',
        'resource_id' => $task->id,
    ]);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, 1);
    expect($occurrence->fresh()->status)->toBe('enqueued');
});

it('keeps publishing pending occurrences after one of them fails', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    $logPath = captureScheduledJobManagerTestLog('scheduled-errors');
    $database = createScheduledBackupDatabase();
    $occurrences = collect([createScheduledDatabaseBackup($database), createScheduledDatabaseBackup($database)])
        ->map(fn (ScheduledDatabaseBackup $backup) => ScheduledJobDelivery::create([
            'schedule_key' => "scheduled-backup:{$backup->id}",
            'scheduled_for' => now()->startOfMinute(),
            'job_type' => 'database-backup',
            'resource_id' => $backup->id,
            'status' => 'pending',
        ]));

    $dispatchAttempts = 0;
    Bus::shouldReceive('dispatch')->andReturnUsing(function () use (&$dispatchAttempts) {
        if (++$dispatchAttempts === 1) {
            throw new RuntimeException('queue connection lost');
        }
    });

    app(ScheduledJobDeliveryService::class)->publishPending();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect($occurrences->first()->fresh()->status)->toBe('pending')
        ->and($occurrences->last()->fresh()->status)->toBe('enqueued')
        ->and($log)->toContain('Failed to publish pending scheduled occurrence')
        ->toContain('queue connection lost');
});

it('allows only one worker to claim an occurrence', function () {
    $occurrence = ScheduledJobDelivery::create([
        'schedule_key' => 'scheduled-task:claim-test',
        'scheduled_for' => now(),
        'job_type' => 'scheduled-task',
        'resource_id' => 1,
        'status' => 'enqueued',
    ]);
    $service = app(ScheduledJobDeliveryService::class);

    expect($service->claim($occurrence->uuid, 'worker-a'))->toBeTrue()
        ->and($service->claim($occurrence->uuid, 'worker-b'))->toBeFalse()
        ->and($service->claim($occurrence->uuid, 'worker-a'))->toBeTrue()
        ->and($occurrence->fresh()->status)->toBe('claimed');

    $service->complete($occurrence->uuid, 'worker-a');

    expect($occurrence->fresh())->toBeNull();
});

it('deletes only failed old delivery records', function () {
    $oldFailed = ScheduledJobDelivery::create([
        'schedule_key' => 'scheduled-task:old-failed',
        'scheduled_for' => now()->subDays(31),
        'job_type' => 'scheduled-task',
        'resource_id' => 1,
        'status' => 'failed',
    ]);
    $oldPending = ScheduledJobDelivery::create([
        'schedule_key' => 'scheduled-task:old-pending',
        'scheduled_for' => now()->subDays(31),
        'job_type' => 'scheduled-task',
        'resource_id' => 1,
        'status' => 'pending',
    ]);
    $oldFailed->timestamps = false;
    $oldFailed->forceFill(['created_at' => now()->subDays(31)])->save();
    $oldPending->timestamps = false;
    $oldPending->forceFill(['created_at' => now()->subDays(31)])->save();

    app(ScheduledJobDeliveryService::class)->deleteOldOccurrences();

    expect($oldFailed->fresh())->toBeNull()
        ->and($oldPending->fresh())->not->toBeNull();
});

it('marks stale claimed deliveries as failed', function () {
    $delivery = ScheduledJobDelivery::create([
        'schedule_key' => 'scheduled-task:stale-claim',
        'scheduled_for' => now()->subDays(3),
        'job_type' => 'scheduled-task',
        'resource_id' => 1,
        'status' => 'claimed',
        'claim_token' => 'lost-worker',
    ]);
    $delivery->timestamps = false;
    $delivery->forceFill(['updated_at' => now()->subDays(3)])->save();

    app(ScheduledJobDeliveryService::class)->deleteOldOccurrences();

    expect($delivery->fresh()->status)->toBe('failed');
});

it('dispatches zero-id schedules and continues with positive ids', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 0, 30, 'UTC'));
    $application = createScheduledTaskApplication();
    $database = StandalonePostgresql::create([
        'name' => 'coolify-db',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'running',
        'environment_id' => $application->environment_id,
        'destination_id' => $application->destination_id,
        'destination_type' => $application->destination_type,
    ]);
    $zeroIdBackup = createScheduledDatabaseBackup($database, ['id' => 0]);
    $positiveIdBackup = createScheduledDatabaseBackup($database);
    $zeroIdTask = createScheduledApplicationTask($application, ['id' => 0]);
    $positiveIdTask = createScheduledApplicationTask($application);

    expect($zeroIdBackup->id)->toBe(0)->and($zeroIdTask->id)->toBe(0);

    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DatabaseBackupJob::class, 2);
    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->backup->id === 0);
    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->backup->id === $positiveIdBackup->id);
    Queue::assertPushed(ScheduledTaskJob::class, 2);
    Queue::assertPushed(ScheduledTaskJob::class, fn (ScheduledTaskJob $job) => $job->task->id === 0);
    Queue::assertPushed(ScheduledTaskJob::class, fn (ScheduledTaskJob $job) => $job->task->id === $positiveIdTask->id);
});

it('does not query relationships when constructing scheduled task jobs', function () {
    $task = createScheduledApplicationTask(createScheduledTaskApplication());

    DB::flushQueryLog();
    DB::enableQueryLog();

    $job = new ScheduledTaskJob($task);

    expect(DB::getQueryLog())->toBeEmpty()
        ->and($job->queue)->toBe(crons_queue())
        ->and($job->timeout)->toBe(300);
});

it('reads current server settings in each manager run of the same process', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0, 0, 'UTC'));
    $database = createScheduledBackupDatabase();
    createScheduledDatabaseBackup($database, ['frequency' => 'daily']);
    $serverId = $database->destination->server_id;
    ServerSetting::query()->where('server_id', $serverId)->update(['is_reachable' => false]);

    Carbon::setTestNow(Carbon::create(2026, 9, 16, 0, 0, 5, 'UTC'));
    $this->artisan('scheduled:dispatch')->assertSuccessful();
    Queue::assertNotPushed(DatabaseBackupJob::class);

    // Another worker marks the server reachable again before the next daily run.
    ServerSetting::query()->where('server_id', $serverId)->update(['is_reachable' => true]);

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 5, 'UTC'));
    $this->artisan('scheduled:dispatch')->assertSuccessful();

    Queue::assertPushed(DatabaseBackupJob::class, 1);
});

it('logs each step of a scheduled occurrence', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 12, 0, 0, 'UTC'));
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'daily']);
    $logPath = captureScheduledJobManagerTestLog('scheduled');

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    (new ScheduledJobManager)->handle();
    $occurrenceUuid = ScheduledJobDelivery::query()->where('schedule_key', "scheduled-backup:{$backup->id}")->value('uuid');
    $service = app(ScheduledJobDeliveryService::class);

    expect($service->claim($occurrenceUuid, 'worker-a'))->toBeTrue()
        ->and($service->claim($occurrenceUuid, 'worker-b'))->toBeFalse();
    $service->complete($occurrenceUuid, 'worker-a');

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect($log)->toContain('Backup dispatched')
        ->toContain('Scheduled occurrence enqueued')
        ->toContain('"occurrence_uuid":"'.$occurrenceUuid.'"')
        ->toContain('Scheduled occurrence claimed')
        ->toContain('Scheduled occurrence claim rejected')
        ->toContain('Scheduled occurrence completed')
        ->toContain("\"schedule_key\":\"scheduled-backup:{$backup->id}\"")
        ->toContain('"seconds_since_due":300');
});

it('logs a backup run that ends early, even when it completes before the enqueue log', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 16, 12, 0, 0, 'UTC'));
    Event::fake([BackupCreated::class]);
    Queue::fakeExcept([DatabaseBackupJob::class]);
    $database = createScheduledBackupDatabase();
    $database->update(['status' => 'exited']);
    $backup = createScheduledDatabaseBackup($database, ['frequency' => 'daily']);
    $logPath = captureScheduledJobManagerTestLog('scheduled');

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    $this->artisan('scheduled:dispatch')->assertSuccessful();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect($log)->toContain('Database backup job ended without a backup')
        ->toContain('"skip_reason":"database_not_running"')
        ->toContain('Scheduled occurrence completed')
        ->toMatch('/Scheduled occurrence enqueued \{[^\n]*"schedule_key":"scheduled-backup:'.$backup->id.'"[^\n]*"status":"enqueued"/')
        ->and(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-backup:{$backup->id}")->exists())->toBeFalse();
});

function captureScheduledJobManagerTestLog(string $channel): string
{
    $path = tempnam(sys_get_temp_dir(), 'coolify-scheduled-log-');
    config(["logging.channels.{$channel}" => ['driver' => 'single', 'path' => $path, 'level' => 'debug']]);
    Log::forgetChannel($channel);

    return $path;
}

function createScheduledTaskApplication(): Application
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $team->id,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'docker_cleanup_frequency' => '0 * * * *',
    ]);

    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'status' => 'running',
    ]);
}

function createScheduledBackupDatabase(): StandalonePostgresql
{
    $application = createScheduledTaskApplication();

    return StandalonePostgresql::create([
        'name' => 'coolify-db',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'running',
        'environment_id' => $application->environment_id,
        'destination_id' => $application->destination_id,
        'destination_type' => $application->destination_type,
    ]);
}

function createScheduledDatabaseBackup(StandalonePostgresql $database, array $overrides = []): ScheduledDatabaseBackup
{
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill(array_merge([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '* * * * *',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $database->environment->project->team_id,
    ], $overrides));
    $backup->save();

    return $backup->fresh();
}

function createScheduledServiceDatabaseBackup(array $overrides = []): ScheduledDatabaseBackup
{
    $application = createScheduledTaskApplication();
    $service = Service::factory()->create([
        'environment_id' => $application->environment_id,
        'destination_id' => $application->destination_id,
        'destination_type' => $application->destination_type,
    ]);
    $database = ServiceDatabase::create([
        'name' => 'postgres',
        'image' => 'postgres:16-alpine',
        'service_id' => $service->id,
    ]);

    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill(array_merge([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '* * * * *',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $application->environment->project->team_id,
    ], $overrides));
    $backup->save();

    return $backup->fresh();
}

function createScheduledApplicationTask(Application $application, array $overrides = []): ScheduledTask
{
    $task = new ScheduledTask;
    $task->forceFill(array_merge([
        'name' => 'scheduled-task',
        'command' => 'echo hello',
        'frequency' => '* * * * *',
        'timeout' => 300,
        'enabled' => true,
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
    ], $overrides));
    $task->save();

    return $task->fresh();
}
