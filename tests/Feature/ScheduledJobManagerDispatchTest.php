<?php

use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

it('dispatches zero-id schedules and continues with positive ids', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    Queue::fake();

    $application = createScheduledTaskApplication();
    $database = StandalonePostgresql::create([
        'name' => 'scheduled-backup-database',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $application->environment_id,
        'destination_id' => $application->destination_id,
        'destination_type' => $application->destination_type,
        'status' => 'running',
    ]);

    $zeroIdBackup = ScheduledDatabaseBackup::forceCreate([
        'id' => 0,
        'team_id' => $application->environment->project->team_id,
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $positiveIdBackup = ScheduledDatabaseBackup::create([
        'team_id' => $application->environment->project->team_id,
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $zeroIdTask = ScheduledTask::forceCreate([
        'id' => 0,
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'name' => 'Zero ID task',
        'command' => 'echo zero',
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $positiveIdTask = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);

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

it('skips expensive dispatch for non-due schedules while seeding dedup cache', function () {
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
    expect(Cache::get("scheduled-task:{$task->id}"))->not->toBeNull();
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
