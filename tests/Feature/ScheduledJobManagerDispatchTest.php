<?php

use App\Events\BackupCreated;
use App\Events\DockerCleanupDone;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\DockerCleanupExecution;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledJobState;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\ScheduledTask\TaskFailed;
use App\Notifications\Server\DockerCleanupFailed;
use App\Services\ScheduledJobDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

it('dispatches scheduled tasks across chunks', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    Queue::fake();

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
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'status' => 'running',
    ]);

    ScheduledTask::factory()
        ->count(101)
        ->create([
            'team_id' => $team->id,
            'application_id' => $application->id,
            'frequency' => '* * * * *',
            'enabled' => true,
        ]);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, 101);
});

it('skips expensive dispatch for schedules outside the catch-up window', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    Queue::fake();

    $application = createScheduledTaskApplication();

    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '0 2 * * *',
        'enabled' => true,
    ]);

    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-task:{$task->id}")->exists())->toBeFalse();
});

it('dispatches a recently missed daily task when deduplication cache is empty', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 10, 0, 'UTC'));
    Queue::fake();

    $application = createScheduledTaskApplication();

    ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => 'daily',
        'enabled' => true,
    ]);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, 1);
});

it('dispatches one job when multiple managers evaluate the same occurrence', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    Queue::fake();

    $application = createScheduledTaskApplication();
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => 'daily',
        'enabled' => true,
    ]);

    (new ScheduledJobManager)->handle();
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, 1);
    expect(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-task:{$task->id}")->count())->toBe(1)
        ->and(ScheduledJobState::query()->where('schedule_key', "scheduled-task:{$task->id}")->count())->toBe(1);
});

it('does not retry an occurrence skipped while its server is not functional', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    Queue::fake();

    $application = createScheduledTaskApplication();
    $task = createScheduledApplicationTask($application, ['frequency' => 'daily']);
    $server = $task->server();
    $server->settings()->update(['is_reachable' => false]);

    (new ScheduledJobManager)->handle();

    $server->settings()->update(['is_reachable' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 6, 0, 'UTC'));
    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect(ScheduledJobState::query()->where('schedule_key', "scheduled-task:{$task->id}")->value('last_scheduled_for'))
        ->not->toBeNull()
        ->and(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-task:{$task->id}")->exists())
        ->toBeFalse();
});

it('does not publish a task occurrence when its application is not running', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    Queue::fake();

    $application = createScheduledTaskApplication();
    $application->update(['status' => 'stopped']);
    $task = createScheduledApplicationTask($application, ['frequency' => 'daily']);

    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect(ScheduledJobState::query()->where('schedule_key', "scheduled-task:{$task->id}")->exists())->toBeTrue()
        ->and(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-task:{$task->id}")->exists())
        ->toBeFalse();
});

it('publishes a pending occurrence after a previous publisher interruption', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();

    $application = createScheduledTaskApplication();
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => 'daily',
        'enabled' => true,
    ]);
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

it('dispatches the instance coolify-db backup even when its id is zero', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    Queue::fake();

    $database = createScheduledBackupDatabase();
    $backup = createScheduledDatabaseBackup($database, [
        'id' => 0,
        'frequency' => '* * * * *',
    ]);

    expect($backup->id)->toBe(0);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DatabaseBackupJob::class, 1);
    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->backup->id === 0);
});

it('dispatches zero-id schedules and continues with positive ids', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    Queue::fake();

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

    expect($zeroIdBackup->id)->toBe(0)
        ->and($positiveIdBackup->id)->toBeGreaterThan(0)
        ->and($zeroIdTask->id)->toBe(0)
        ->and($positiveIdTask->id)->toBeGreaterThan(0);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DatabaseBackupJob::class, 2);
    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->backup->id === 0);
    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->backup->id === $positiveIdBackup->id);
    Queue::assertPushed(ScheduledTaskJob::class, 2);
    Queue::assertPushed(ScheduledTaskJob::class, fn (ScheduledTaskJob $job) => $job->task->id === 0);
    Queue::assertPushed(ScheduledTaskJob::class, fn (ScheduledTaskJob $job) => $job->task->id === $positiveIdTask->id);
});

it('does not query relationships when constructing scheduled task jobs', function () {
    $application = createScheduledTaskApplication();

    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ])->fresh();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $job = new ScheduledTaskJob($task);

    expect(DB::getQueryLog())->toBeEmpty()
        ->and($job->queue)->toBe(crons_queue())
        ->and($job->timeout)->toBe(300);
});

it('reads current server settings in each queued manager run of the same worker', function () {
    config(['constants.coolify.self_hosted' => true]);
    Queue::fakeExcept(ScheduledJobManager::class);

    $database = createScheduledBackupDatabase();
    createScheduledDatabaseBackup($database, ['frequency' => 'daily']);
    $serverId = $database->destination->server_id;
    ServerSetting::query()->where('server_id', $serverId)->update(['is_reachable' => false]);

    Carbon::setTestNow(Carbon::create(2026, 9, 16, 0, 5, 0, 'UTC'));
    dispatch(new ScheduledJobManager);
    Queue::assertNotPushed(DatabaseBackupJob::class);

    // Another worker marks the server reachable again before the next daily run.
    ServerSetting::query()->where('server_id', $serverId)->update(['is_reachable' => true]);

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    dispatch(new ScheduledJobManager);

    Queue::assertPushed(DatabaseBackupJob::class, 1);
});

it('logs the current database server state when a backup is skipped', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    Queue::fake();
    $logPath = captureScheduledJobManagerTestLog('scheduled');

    $database = createScheduledBackupDatabase();
    $backup = createScheduledDatabaseBackup($database, ['frequency' => 'daily']);
    ServerSetting::query()->where('server_id', $database->destination->server_id)->update(['is_reachable' => false]);

    (new ScheduledJobManager)->handle();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect($log)->toContain('Backup skipped')
        ->toContain('"skip_reason":"server_not_functional"')
        ->toContain("\"backup_id\":{$backup->id}")
        ->toContain('"db_is_reachable":false');
});

it('logs each step of a scheduled occurrence', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    Queue::fake();
    $logPath = captureScheduledJobManagerTestLog('scheduled');

    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'daily']);
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
        ->toContain('"host":"'.gethostname().'"')
        ->toContain('Scheduled occurrence claimed')
        ->toContain('Scheduled occurrence claim rejected')
        ->toContain('Scheduled occurrence completed')
        ->toContain("\"schedule_key\":\"scheduled-backup:{$backup->id}\"")
        ->toContain('"seconds_since_due":300');
});

it('warns when the manager starts after the catch-up window', function (int $minutesSincePreviousStart, bool $expectWarning) {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 30, 0, 'UTC'));
    Queue::fake();
    $logPath = captureScheduledJobManagerTestLog('scheduled-errors');
    Cache::put('scheduled-job-manager:last-started-at', now()->subMinutes($minutesSincePreviousStart)->toIso8601String());

    (new ScheduledJobManager)->handle();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect(str_contains($log, 'ScheduledJobManager started late'))->toBe($expectWarning);
})->with([
    'normal one-minute interval' => [1, false],
    'twenty-minute gap' => [20, true],
]);

it('warns when an occurrence is enqueued while the previous one is still running', function () {
    config(['constants.coolify.self_hosted' => true]);
    Queue::fake();
    $logPath = captureScheduledJobManagerTestLog('scheduled');
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'hourly']);

    Carbon::setTestNow(Carbon::create(2026, 9, 16, 0, 5, 0, 'UTC'));
    (new ScheduledJobManager)->handle();
    $previousUuid = ScheduledJobDelivery::query()->where('schedule_key', "scheduled-backup:{$backup->id}")->value('uuid');
    app(ScheduledJobDeliveryService::class)->claim($previousUuid, 'worker-a');

    Carbon::setTestNow(Carbon::create(2026, 9, 16, 1, 5, 0, 'UTC'));
    (new ScheduledJobManager)->handle();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect($log)->toContain('Scheduled occurrence enqueued while the previous occurrence is still open')
        ->toContain('"previous_occurrence_uuid":"'.$previousUuid.'"')
        ->toContain('"previous_status":"claimed"');
});

it('reports occurrences that stay open after fifteen minutes', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 30, 0, 'UTC'));
    Queue::fake();
    $logPath = captureScheduledJobManagerTestLog('scheduled');

    foreach (['enqueued' => 20, 'claimed' => 20, 'failed' => 20] as $status => $minutesAgo) {
        ScheduledJobDelivery::create([
            'schedule_key' => "scheduled-backup:{$status}",
            'scheduled_for' => now()->subMinutes($minutesAgo),
            'job_type' => 'database-backup',
            'resource_id' => 1,
            'status' => $status,
        ])->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
    }
    ScheduledJobDelivery::create([
        'schedule_key' => 'scheduled-backup:recent',
        'scheduled_for' => now()->subMinutes(2),
        'job_type' => 'database-backup',
        'resource_id' => 1,
        'status' => 'enqueued',
    ]);

    (new ScheduledJobManager)->handle();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect($log)->toContain('"open_occurrences_over_15_minutes":{"claimed":1,"enqueued":1}');
});

it('keeps publishing pending occurrences after one of them fails', function () {
    config(['constants.coolify.self_hosted' => true]);
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

it('logs a backup run that ends early, even when it completes before the enqueue log', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    Event::fake([BackupCreated::class]);
    Queue::fakeExcept([ScheduledJobManager::class, DatabaseBackupJob::class]);
    $logPath = captureScheduledJobManagerTestLog('scheduled');

    $database = createScheduledBackupDatabase();
    $database->update(['status' => 'exited']);
    $backup = createScheduledDatabaseBackup($database, ['frequency' => 'daily']);

    dispatch(new ScheduledJobManager);

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect($log)->toContain('Database backup job ended without a backup')
        ->toContain('"skip_reason":"database_not_running"')
        ->toContain('"database_status":"exited:unhealthy"')
        ->toContain('Scheduled occurrence completed')
        ->toMatch('/Scheduled occurrence enqueued \{[^\n]*"schedule_key":"scheduled-backup:'.$backup->id.'"[^\n]*"status":"enqueued"/')
        ->and(ScheduledJobDelivery::query()->where('schedule_key', "scheduled-backup:{$backup->id}")->exists())->toBeFalse();
});

it('runs the manager in the scheduler process through the scheduled:dispatch command', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    Queue::fakeExcept(ScheduledJobManager::class);

    $application = createScheduledTaskApplication();
    createScheduledApplicationTask($application, ['frequency' => '* * * * *']);

    $this->artisan('scheduled:dispatch')->assertSuccessful();

    Queue::assertNotPushed(ScheduledJobManager::class);
    Queue::assertPushed(ScheduledTaskJob::class, 1);
});

it('loads the server chain of scheduled database backups without a query for each backup', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    Queue::fake();

    $database = createScheduledBackupDatabase();
    createScheduledDatabaseBackup($database, ['frequency' => '0 2 * * *']);
    foreach (range(1, 2) as $number) {
        $otherDatabase = StandalonePostgresql::create([
            ...$database->only(['image', 'postgres_user', 'postgres_password', 'postgres_db', 'status', 'environment_id', 'destination_id', 'destination_type']),
            'name' => "database-{$number}",
        ]);
        createScheduledDatabaseBackup($otherDatabase, ['frequency' => '0 2 * * *']);
    }
    createScheduledServiceDatabaseBackup(['frequency' => '0 2 * * *'], Application::query()->first());

    DB::flushQueryLog();
    DB::enableQueryLog();
    (new ScheduledJobManager)->handle();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $perBackupLookups = $queries->filter(fn (string $query) => str_contains($query, '"standalone_dockers"."id" = ?')
        || str_contains($query, '"services"."id" = ?')
        || str_contains($query, '"server_settings"."server_id" = ?'));

    expect($perBackupLookups)->toBeEmpty();
});

it('dispatches a due backup of a service database', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    Queue::fake();

    $backup = createScheduledServiceDatabaseBackup(['frequency' => '* * * * *']);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->backup->is($backup));
});

it('keeps dispatching other schedules when one schedule throws a PHP error', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 5, 0, 'UTC'));
    $logPath = captureScheduledJobManagerTestLog('scheduled-errors');
    $database = createScheduledBackupDatabase();
    createScheduledDatabaseBackup($database);
    createScheduledDatabaseBackup($database);

    $dispatchAttempts = 0;
    Bus::shouldReceive('dispatch')->andReturnUsing(function () use (&$dispatchAttempts) {
        if (++$dispatchAttempts === 1) {
            throw new Error('broken schedule');
        }
    });

    (new ScheduledJobManager)->handle();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    // The first backup stays pending for publishPending(); the second one is still enqueued.
    expect(ScheduledJobDelivery::query()->where('job_type', 'database-backup')->orderBy('id')->pluck('status')->all())->toBe(['pending', 'enqueued'])
        ->and($log)->toContain('Error processing backup')
        ->toContain('broken schedule');
});

it('republishes a backup occurrence whose queued job was lost, and the old job cannot run it again', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'daily']);
    $occurrence = createStaleEnqueuedOccurrence("scheduled-backup:{$backup->id}", 'database-backup', $backup->id, Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->occurrenceUuid === $occurrence->uuid);
    expect($occurrence->fresh()->status)->toBe('enqueued')
        ->and($occurrence->fresh()->enqueued_at->toDateTimeString())->toBe('2026-09-17 12:00:00');

    $service = app(ScheduledJobDeliveryService::class);
    expect($service->claim($occurrence->uuid, 'republished-job'))->toBeTrue()
        ->and($service->claim($occurrence->uuid, 'original-job'))->toBeFalse();
});

it('does not run a lost backup occurrence again when a newer occurrence exists', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    $logPath = captureScheduledJobManagerTestLog('scheduled');
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'hourly']);
    $lost = createStaleEnqueuedOccurrence("scheduled-backup:{$backup->id}", 'database-backup', $backup->id, Carbon::create(2026, 9, 17, 10, 0, 0, 'UTC'));
    createStaleEnqueuedOccurrence("scheduled-backup:{$backup->id}", 'database-backup', $backup->id, Carbon::create(2026, 9, 17, 11, 0, 0, 'UTC'), minutesAgo: 5);

    (new ScheduledJobManager)->handle();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    Queue::assertNotPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->occurrenceUuid === $lost->uuid);
    expect($lost->fresh()->status)->toBe('failed')
        ->and($log)->toContain('Scheduled occurrence skipped: its queued job was not started and a newer occurrence exists');
});

it('logs a lost scheduled task occurrence as missed instead of running it late', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    $logPath = captureScheduledJobManagerTestLog('scheduled');
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    $occurrence = createStaleEnqueuedOccurrence("scheduled-task:{$task->id}", 'scheduled-task', $task->id, Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));

    (new ScheduledJobManager)->handle();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect($occurrence->fresh()->status)->toBe('failed')
        ->and(app(ScheduledJobDeliveryService::class)->claim($occurrence->uuid, 'original-job'))->toBeFalse()
        ->and($log)->toContain('Scheduled occurrence missed: its queued job was not started');
});

it('records a missed scheduled task as a failed execution and notifies the team once', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    Notification::fake();
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Team::find($task->team_id)->emailNotificationSettings->update(['smtp_enabled' => true, 'scheduled_task_failure_email_notifications' => true]);
    $occurrence = createStaleEnqueuedOccurrence("scheduled-task:{$task->id}", 'scheduled-task', $task->id, Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));

    (new ScheduledJobManager)->handle();
    (new ScheduledJobManager)->handle();

    $executions = ScheduledTaskExecution::query()->where('scheduled_task_id', $task->id)->get();
    expect($executions)->toHaveCount(1)
        ->and($executions->first()->status)->toBe('failed')
        ->and($executions->first()->message)->toBe('Skipped: the queued job did not start within 60 minutes.')
        ->and($executions->first()->finished_at)->not->toBeNull();
    Notification::assertSentToTimes(Team::find($task->team_id), TaskFailed::class, 1);
    Notification::assertSentTo(Team::find($task->team_id), TaskFailed::class, fn (TaskFailed $notification) => $notification->task->is($task)
        && $notification->output === 'Skipped: the queued job did not start within 60 minutes.');

    $lateJob = new ScheduledTaskJob($task, $occurrence->uuid);
    $lateJob->handle();

    expect(ScheduledTaskExecution::query()->where('scheduled_task_id', $task->id)->count())->toBe(1);
});

it('records a missed Docker cleanup as a failed execution and notifies the team once', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    Notification::fake();
    Event::fake([DockerCleanupDone::class]);
    $server = createScheduledTaskApplication()->destination->server;
    $server->team->emailNotificationSettings->update(['smtp_enabled' => true, 'docker_cleanup_failure_email_notifications' => true]);
    $occurrence = createStaleEnqueuedOccurrence("docker-cleanup:{$server->id}", 'docker-cleanup', $server->id, Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));

    (new ScheduledJobManager)->handle();
    (new ScheduledJobManager)->handle();

    $executions = DockerCleanupExecution::query()->where('server_id', $server->id)->get();
    expect($executions)->toHaveCount(1)
        ->and($executions->first()->status)->toBe('failed')
        ->and($executions->first()->message)->toBe('Skipped: the queued job did not start within 60 minutes.')
        ->and($executions->first()->finished_at)->not->toBeNull()
        ->and($occurrence->fresh()->status)->toBe('failed')
        ->and(app(ScheduledJobDeliveryService::class)->claim($occurrence->uuid, 'original-job'))->toBeFalse();
    Notification::assertSentToTimes($server->team, DockerCleanupFailed::class, 1);
    Event::assertDispatched(DockerCleanupDone::class, 1);
});

it('sends at most one missed-run notification per hour for the same schedule', function (string $jobType) {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    Notification::fake();
    Event::fake([DockerCleanupDone::class]);
    if ($jobType === 'scheduled-task') {
        $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
        InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
        $team = Team::find($task->team_id);
        $team->emailNotificationSettings->update(['smtp_enabled' => true, 'scheduled_task_failure_email_notifications' => true]);
        [$scheduleKey, $resourceId, $notification] = ["scheduled-task:{$task->id}", $task->id, TaskFailed::class];
        $executions = fn () => ScheduledTaskExecution::query()->where('scheduled_task_id', $task->id)->count();
    } else {
        $server = createScheduledTaskApplication()->destination->server;
        $team = $server->team;
        $team->emailNotificationSettings->update(['smtp_enabled' => true, 'docker_cleanup_failure_email_notifications' => true]);
        [$scheduleKey, $resourceId, $notification] = ["docker-cleanup:{$server->id}", $server->id, DockerCleanupFailed::class];
        $executions = fn () => DockerCleanupExecution::query()->where('server_id', $server->id)->count();
    }

    // Workers are down: three runs of the same schedule were missed.
    foreach ([0, 1, 2] as $minute) {
        createStaleEnqueuedOccurrence($scheduleKey, $jobType, $resourceId, Carbon::create(2026, 9, 17, 10, $minute, 0, 'UTC'));
    }
    (new ScheduledJobManager)->handle();

    expect($executions())->toBe(3);
    Notification::assertSentToTimes($team, $notification, 1);

    // More than an hour later, a new missed run is reported again.
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 13, 5, 0, 'UTC'));
    createStaleEnqueuedOccurrence($scheduleKey, $jobType, $resourceId, Carbon::create(2026, 9, 17, 11, 30, 0, 'UTC'));
    (new ScheduledJobManager)->handle();

    // Each missed run still gets its own failed execution (Docker cleanup also has its own 12:00 run).
    expect($executions())->toBeGreaterThanOrEqual(4);
    Notification::assertSentToTimes($team, $notification, 2);
})->with(['scheduled task' => 'scheduled-task', 'docker cleanup' => 'docker-cleanup']);

it('leaves recently enqueued occurrences alone', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'daily']);
    $occurrence = createStaleEnqueuedOccurrence("scheduled-backup:{$backup->id}", 'database-backup', $backup->id, Carbon::create(2026, 9, 17, 11, 0, 0, 'UTC'), minutesAgo: ScheduledJobDeliveryService::ENQUEUED_STALE_AFTER_MINUTES - 1);

    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(DatabaseBackupJob::class);
    expect($occurrence->fresh()->enqueued_at->toDateTimeString())->toBe('2026-09-17 11:01:00');
});

it('dispatches only the schedules of its own type', function (string $type, array $expected) {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 1, 0, 0, 'UTC'));
    Queue::fakeExcept(ScheduledJobManager::class);
    $application = createScheduledTaskApplication();
    createScheduledApplicationTask($application, ['frequency' => '* * * * *']);
    createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => '* * * * *']);

    $this->artisan('scheduled:dispatch', ['--type' => $type])->assertSuccessful();

    Queue::assertPushed(DatabaseBackupJob::class, $expected['backups']);
    Queue::assertPushed(ScheduledTaskJob::class, $expected['tasks']);
    Queue::assertPushed(DockerCleanupJob::class, $expected['docker-cleanups']);
})->with([
    'backups' => ['backups', ['backups' => 1, 'tasks' => 0, 'docker-cleanups' => 0]],
    'tasks' => ['tasks', ['backups' => 0, 'tasks' => 1, 'docker-cleanups' => 0]],
    // Two servers: the task application and the backup database are on separate test servers.
    'docker cleanups' => ['docker-cleanups', ['backups' => 0, 'tasks' => 0, 'docker-cleanups' => 2]],
]);

it('dispatches each occurrence once when the type dispatchers run after each other', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 1, 0, 0, 'UTC'));
    Queue::fakeExcept(ScheduledJobManager::class);
    createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => '* * * * *']);
    createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => '* * * * *']);

    foreach (array_keys(ScheduledJobManager::TYPES) as $type) {
        $this->artisan('scheduled:dispatch', ['--type' => $type])->assertSuccessful();
    }
    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DatabaseBackupJob::class, 1);
    Queue::assertPushed(ScheduledTaskJob::class, 1);
    Queue::assertPushed(DockerCleanupJob::class, 2);
});

it('publishes and recovers only the deliveries of its own type', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fakeExcept(ScheduledJobManager::class);
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'daily']);
    $pendingTask = ScheduledJobDelivery::create([
        'schedule_key' => "scheduled-task:{$task->id}",
        'scheduled_for' => Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'),
        'job_type' => 'scheduled-task',
        'resource_id' => $task->id,
    ]);
    $lostBackup = createStaleEnqueuedOccurrence("scheduled-backup:{$backup->id}", 'database-backup', $backup->id, Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));

    $this->artisan('scheduled:dispatch', ['--type' => 'tasks'])->assertSuccessful();

    Queue::assertPushed(ScheduledTaskJob::class, 1);
    Queue::assertNotPushed(DatabaseBackupJob::class);
    expect($pendingTask->fresh()->status)->toBe('enqueued')
        ->and($lostBackup->fresh()->status)->toBe('enqueued');

    $this->artisan('scheduled:dispatch', ['--type' => 'backups'])->assertSuccessful();

    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->occurrenceUuid === $lostBackup->uuid);
});

it('uses a separate overlap lock for each type', function () {
    $lockKeys = collect([null, ...array_keys(ScheduledJobManager::TYPES)])
        ->map(fn (?string $type) => (new ReflectionProperty(WithoutOverlapping::class, 'key'))->getValue((new ScheduledJobManager($type))->middleware()[0]));

    expect($lockKeys->all())->toBe([
        'scheduled-job-manager',
        'scheduled-job-manager:backups',
        'scheduled-job-manager:tasks',
        'scheduled-job-manager:volume-backups',
        'scheduled-job-manager:docker-cleanups',
    ]);
});

it('rejects an unknown schedule type', function () {
    Queue::fake();

    $this->artisan('scheduled:dispatch', ['--type' => 'invalid'])->assertFailed();

    Queue::assertNothingPushed();
});

it('reports a task occurrence as missed when its publication kept failing for an hour', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    Notification::fake();
    $task = createScheduledApplicationTask(createScheduledTaskApplication(), ['frequency' => 'daily']);
    $occurrence = ScheduledJobDelivery::create([
        'schedule_key' => "scheduled-task:{$task->id}",
        'scheduled_for' => Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'),
        'job_type' => 'scheduled-task',
        'resource_id' => $task->id,
        'status' => 'pending',
    ]);
    $occurrence->forceFill(['created_at' => now()->subMinutes(ScheduledJobDeliveryService::ENQUEUED_STALE_AFTER_MINUTES + 1)])->save();

    app(ScheduledJobDeliveryService::class)->publishPending();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect($occurrence->fresh()->status)->toBe('failed')
        ->and(ScheduledTaskExecution::query()->where('scheduled_task_id', $task->id)->value('status'))->toBe('failed');
});

it('publishes a backup occurrence once late when its publication kept failing and no newer occurrence exists', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'daily']);
    $occurrence = ScheduledJobDelivery::create([
        'schedule_key' => "scheduled-backup:{$backup->id}",
        'scheduled_for' => Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'),
        'job_type' => 'database-backup',
        'resource_id' => $backup->id,
        'status' => 'pending',
    ]);
    $occurrence->forceFill(['created_at' => now()->subHours(2)])->save();

    app(ScheduledJobDeliveryService::class)->publishPending();

    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job) => $job->occurrenceUuid === $occurrence->uuid);
    expect($occurrence->fresh()->status)->toBe('enqueued');
});

it('does not run a lost backup occurrence late when it is older than the late-run limit', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    Queue::fake();
    $logPath = captureScheduledJobManagerTestLog('scheduled');
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => 'weekly']);
    $occurrence = createStaleEnqueuedOccurrence(
        "scheduled-backup:{$backup->id}",
        'database-backup',
        $backup->id,
        now()->subHours(ScheduledJobDeliveryService::LATE_RUN_MAX_AGE_HOURS + 1),
        minutesAgo: (ScheduledJobDeliveryService::LATE_RUN_MAX_AGE_HOURS + 1) * 60,
    );

    app(ScheduledJobDeliveryService::class)->recoverStaleEnqueued();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    Queue::assertNotPushed(DatabaseBackupJob::class);
    expect($occurrence->fresh()->status)->toBe('failed')
        ->and($log)->toContain('too old to run late');
});

it('fails claimed occurrences whose worker can no longer be running, and keeps recent claims', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 0, 'UTC'));
    $deadAfterSeconds = (int) config('horizon.worker_timeout') + ScheduledJobDeliveryService::INTERRUPTED_CLAIM_MARGIN_MINUTES * 60;
    $create = fn (string $key, int $startedSecondsAgo) => ScheduledJobDelivery::create([
        'schedule_key' => $key,
        'scheduled_for' => now()->subSeconds($startedSecondsAgo),
        'job_type' => 'scheduled-task',
        'resource_id' => 1,
        'status' => 'claimed',
        'claim_token' => "token-{$key}",
        'started_at' => now()->subSeconds($startedSecondsAgo),
    ]);
    $interrupted = $create('scheduled-task:interrupted', $deadAfterSeconds + 60);
    $running = $create('scheduled-task:running', $deadAfterSeconds - 60);

    $service = app(ScheduledJobDeliveryService::class);
    $service->failInterruptedClaims();

    expect($interrupted->fresh()->status)->toBe('failed')
        ->and($running->fresh()->status)->toBe('claimed')
        // The job copy that Redis hands out again later cannot claim the failed occurrence.
        ->and($service->claim($interrupted->uuid, 'token-scheduled-task:interrupted'))->toBeFalse();
});

it('does not run a scheduled task again when Redis hands it out after its worker was killed', function () {
    $task = createScheduledApplicationTask(createScheduledTaskApplication());
    $occurrence = ScheduledJobDelivery::create([
        'schedule_key' => "scheduled-task:{$task->id}",
        'scheduled_for' => now()->subDay(),
        'job_type' => 'scheduled-task',
        'resource_id' => $task->id,
        'status' => 'claimed',
        'claim_token' => 'killed-worker',
        'started_at' => now()->subDay(),
    ]);

    $job = (new ScheduledTaskJob($task, $occurrence->uuid))->withFakeQueueInteractions();
    $job->job->attempts = 2;
    $job->handle();

    $job->assertFailed();
    expect(ScheduledTaskExecution::query()->where('scheduled_task_id', $task->id)->exists())->toBeFalse();
});

it('marks only the unfinished execution as failed when a scheduled task job fails without its execution id', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Notification::fake();
    $task = createScheduledApplicationTask(createScheduledTaskApplication());
    $interrupted = ScheduledTaskExecution::create(['scheduled_task_id' => $task->id, 'status' => 'running', 'started_at' => now()->subHours(2)]);
    $interrupted->forceFill(['created_at' => now()->subHours(2)])->save();
    $later = ScheduledTaskExecution::create(['scheduled_task_id' => $task->id, 'status' => 'success', 'started_at' => now()->subHour(), 'finished_at' => now()->subMinutes(59)]);

    (new ScheduledTaskJob($task))->failed(new RuntimeException('worker killed'));

    expect($interrupted->fresh()->status)->toBe('failed')
        ->and($later->fresh()->status)->toBe('success');
});

it('runs a fixed-time schedule once when daylight saving time repeats its local time', function () {
    $service = app(ScheduledJobDeliveryService::class);
    $record = fn (string $frequency, string $utc) => $service->recordSkipped("dst:{$frequency}", $frequency, 'Europe/Berlin', Carbon::parse($utc, 'UTC'));

    // 2026-10-25: Berlin goes from 03:00 CEST back to 02:00 CET, so 02:30 happens at 00:30Z and 01:30Z.
    expect($record('30 2 * * *', '2026-10-25 00:31:00'))->toBeTrue()
        ->and($record('30 2 * * *', '2026-10-25 01:31:00'))->toBeFalse()
        ->and($record('30 * * * *', '2026-10-25 00:31:00'))->toBeTrue()
        ->and($record('30 * * * *', '2026-10-25 01:31:00'))->toBeTrue();
});

it('publishes one backup when daylight saving time repeats the local backup time', function () {
    config(['constants.coolify.self_hosted' => true]);
    Queue::fake();
    $backup = createScheduledDatabaseBackup(createScheduledBackupDatabase(), ['frequency' => '30 2 * * *']);
    $service = app(ScheduledJobDeliveryService::class);

    foreach (['2026-10-25 00:31:00', '2026-10-25 01:31:00'] as $utc) {
        $service->recordAndPublish("scheduled-backup:{$backup->id}", '30 2 * * *', 'Europe/Berlin', 'database-backup', $backup->id, executionTime: Carbon::parse($utc, 'UTC'));
    }

    Queue::assertPushed(DatabaseBackupJob::class, 1);
});

it('does not run a fixed time that does not exist when daylight saving time starts', function () {
    $service = app(ScheduledJobDeliveryService::class);

    // 2026-03-29: Berlin skips from 02:00 CET to 03:00 CEST, so 02:30 does not exist that day.
    expect($service->recordSkipped('dst:spring', '30 2 * * *', 'Europe/Berlin', Carbon::parse('2026-03-29 01:01:00', 'UTC')))->toBeFalse()
        ->and($service->recordSkipped('dst:spring', '30 2 * * *', 'Europe/Berlin', Carbon::parse('2026-03-30 00:31:00', 'UTC')))->toBeTrue();
});

it('records a midnight schedule in the server timezone, not in UTC', function () {
    $service = app(ScheduledJobDeliveryService::class);

    // Midnight in New York (EDT) is 04:00Z; 00:05Z is 20:05 the day before in New York.
    expect($service->recordSkipped('midnight', '0 0 * * *', 'America/New_York', Carbon::parse('2026-10-03 00:05:00', 'UTC')))->toBeFalse()
        ->and($service->recordSkipped('midnight', '0 0 * * *', 'America/New_York', Carbon::parse('2026-10-03 04:05:00', 'UTC')))->toBeTrue()
        ->and($service->recordSkipped('midnight', '0 0 * * *', 'America/New_York', Carbon::parse('2026-10-03 04:09:00', 'UTC')))->toBeFalse()
        ->and(ScheduledJobState::query()->where('schedule_key', 'midnight')->value('last_scheduled_for')->toDateTimeString())->toBe('2026-10-03 04:00:00');
});

function createStaleEnqueuedOccurrence(string $scheduleKey, string $jobType, int $resourceId, Carbon $scheduledFor, int $minutesAgo = ScheduledJobDeliveryService::ENQUEUED_STALE_AFTER_MINUTES + 1): ScheduledJobDelivery
{
    return ScheduledJobDelivery::create([
        'schedule_key' => $scheduleKey,
        'scheduled_for' => $scheduledFor,
        'job_type' => $jobType,
        'resource_id' => $resourceId,
        'status' => 'enqueued',
        'enqueued_at' => now()->subMinutes($minutesAgo),
    ]);
}

function createScheduledServiceDatabaseBackup(array $overrides = [], ?Application $application = null): ScheduledDatabaseBackup
{
    $application ??= createScheduledTaskApplication();
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
