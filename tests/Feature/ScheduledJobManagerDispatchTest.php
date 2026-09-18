<?php

use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledJobState;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\ScheduledJobDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

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
