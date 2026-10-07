<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Freeze time for consistent testing
    Carbon::setTestNow('2025-01-15 12:00:00');

    // Fake notifications to ensure none are sent
    Notification::fake();
    // app:init pulls the service templates and writes the real repository file,
    // so answer with its current content instead of an empty body.
    Http::fake([
        config('constants.services.official') => Http::response(file_get_contents(service_templates_path())),
        '*' => Http::response(),
    ]);
    Process::fake();
    Queue::fake();

    InstanceSettings::forceCreate(['id' => 0, 'do_not_track' => true]);

    // app:init runs `optimize`, which boots a fresh application with a new in-memory
    // database and caches config into bootstrap/cache. Replace both with no-ops here.
    foreach (['optimize', 'optimize:clear'] as $commandName) {
        app(Kernel::class)->registerCommand(new class($commandName) extends Command
        {
            public function __construct(string $commandName)
            {
                $this->signature = $commandName;

                parent::__construct();
            }

            public function handle(): int
            {
                return self::SUCCESS;
            }
        });
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

test('app:init marks stuck scheduled task executions as failed', function () {
    // Create a team for the scheduled task
    $team = Team::factory()->create();

    // Create a scheduled task
    $scheduledTask = ScheduledTask::factory()->create([
        'team_id' => $team->id,
    ]);

    // Create multiple task executions with 'running' status
    $runningExecution1 = ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'running',
        'started_at' => Carbon::now()->subMinutes(10),
    ]);

    $runningExecution2 = ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'running',
        'started_at' => Carbon::now()->subMinutes(5),
    ]);

    // Create a completed execution (should not be affected)
    $completedExecution = ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'success',
        'started_at' => Carbon::now()->subMinutes(15),
        'finished_at' => Carbon::now()->subMinutes(14),
    ]);

    // Run the app:init command
    Artisan::call('app:init');

    // Refresh models from database
    $runningExecution1->refresh();
    $runningExecution2->refresh();
    $completedExecution->refresh();

    // Assert running executions are now failed
    expect($runningExecution1->status)->toBe('failed')
        ->and($runningExecution1->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and($runningExecution1->finished_at)->not->toBeNull()
        ->and($runningExecution1->finished_at->toDateTimeString())->toBe('2025-01-15 12:00:00');

    expect($runningExecution2->status)->toBe('failed')
        ->and($runningExecution2->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and($runningExecution2->finished_at)->not->toBeNull();

    // Assert completed execution is unchanged
    expect($completedExecution->status)->toBe('success')
        ->and($completedExecution->message)->toBeNull();

    // Assert NO notifications were sent
    Notification::assertNothingSent();
});

test('app:init marks stuck database backup executions as failed', function () {
    // Create a team for the scheduled backup
    $team = Team::factory()->create();

    // Create a database
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandalonePostgresql::create([
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    // Create a scheduled backup
    $scheduledBackup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'team_id' => $team->id,
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
    ]);

    // Create multiple backup executions with 'running' status
    $runningBackup1 = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $scheduledBackup->id,
        'status' => 'running',
        'database_name' => 'test_db',
    ]);

    $runningBackup2 = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $scheduledBackup->id,
        'status' => 'running',
        'database_name' => 'test_db_2',
    ]);

    // Create a successful backup (should not be affected)
    $successfulBackup = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $scheduledBackup->id,
        'status' => 'success',
        'database_name' => 'test_db_3',
        'finished_at' => Carbon::now()->subMinutes(20),
    ]);

    // Run the app:init command
    Artisan::call('app:init');

    // Refresh models from database
    $runningBackup1->refresh();
    $runningBackup2->refresh();
    $successfulBackup->refresh();

    // Assert running backups are now failed
    expect($runningBackup1->status)->toBe('failed')
        ->and($runningBackup1->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and($runningBackup1->finished_at)->not->toBeNull()
        ->and($runningBackup1->finished_at->toDateTimeString())->toBe('2025-01-15 12:00:00');

    expect($runningBackup2->status)->toBe('failed')
        ->and($runningBackup2->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and($runningBackup2->finished_at)->not->toBeNull();

    // Assert successful backup is unchanged
    expect($successfulBackup->status)->toBe('success')
        ->and($successfulBackup->message)->toBeNull();

    // Assert NO notifications were sent
    Notification::assertNothingSent();
});

test('app:init handles cleanup when no stuck executions exist', function () {
    // Create a team
    $team = Team::factory()->create();

    // Create a scheduled task
    $scheduledTask = ScheduledTask::factory()->create([
        'team_id' => $team->id,
    ]);

    // Create only completed executions
    ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'success',
        'started_at' => Carbon::now()->subMinutes(10),
        'finished_at' => Carbon::now()->subMinutes(9),
    ]);

    ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'failed',
        'started_at' => Carbon::now()->subMinutes(20),
        'finished_at' => Carbon::now()->subMinutes(19),
    ]);

    // Run the app:init command (should not fail)
    $exitCode = Artisan::call('app:init');

    // Assert command succeeded
    expect($exitCode)->toBe(0);

    // Assert all executions remain unchanged
    expect(ScheduledTaskExecution::where('status', 'running')->count())->toBe(0)
        ->and(ScheduledTaskExecution::where('status', 'success')->count())->toBe(1)
        ->and(ScheduledTaskExecution::where('status', 'failed')->count())->toBe(1);

    // Assert NO notifications were sent
    Notification::assertNothingSent();
});

test('cleanup does not send notifications even when team has notification settings', function () {
    // Create a team with notification settings enabled
    $team = Team::factory()->create();
    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'smtp_from_address' => 'test@example.com',
    ]);

    // Create a scheduled task
    $scheduledTask = ScheduledTask::factory()->create([
        'team_id' => $team->id,
    ]);

    // Create a running execution
    $runningExecution = ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'running',
        'started_at' => Carbon::now()->subMinutes(5),
    ]);

    // Run the app:init command
    Artisan::call('app:init');

    // Refresh model
    $runningExecution->refresh();

    // Assert execution is failed
    expect($runningExecution->status)->toBe('failed');

    // Assert NO notifications were sent despite team having notification settings
    Notification::assertNothingSent();
});
