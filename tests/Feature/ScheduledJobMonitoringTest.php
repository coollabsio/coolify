<?php

use App\Livewire\Settings\ScheduledJobs;
use App\Models\DockerCleanupExecution;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SchedulerLogParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create root team (id 0) and root user
    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->rootUser = User::factory()->create();
    $this->rootUser->teams()->attach($this->rootTeam, ['role' => 'owner']);

    // Create regular team and user
    $this->regularTeam = Team::factory()->create();
    $this->regularUser = User::factory()->create();
    $this->regularUser->teams()->attach($this->regularTeam, ['role' => 'owner']);
});

test('scheduled jobs page requires instance admin access', function () {
    $this->actingAs($this->regularUser);
    session(['currentTeam' => $this->regularTeam]);

    $response = $this->get(route('settings.scheduled-jobs'));
    $response->assertRedirect(route('dashboard'));
});

test('scheduled jobs page is accessible by instance admin', function () {
    $this->actingAs($this->rootUser);
    session(['currentTeam' => $this->rootTeam]);

    Livewire::test(ScheduledJobs::class)
        ->assertStatus(200)
        ->assertSee('Scheduled Job Issues');
});

test('scheduled jobs page shows failed backup executions', function () {
    $this->actingAs($this->rootUser);
    session(['currentTeam' => $this->rootTeam]);

    $server = Server::factory()->create(['team_id' => $this->rootTeam->id]);

    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->rootTeam->id,
        'frequency' => '0 * * * *',
        'database_id' => 1,
        'database_type' => 'App\Models\StandalonePostgresql',
        'enabled' => true,
    ]);

    ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'failed',
        'message' => 'Backup failed: connection timeout',
    ]);

    Livewire::test(ScheduledJobs::class)
        ->assertStatus(200)
        ->assertSee('Backup');
});

test('scheduled jobs page shows failed cleanup executions', function () {
    $this->actingAs($this->rootUser);
    session(['currentTeam' => $this->rootTeam]);

    $server = Server::factory()->create([
        'team_id' => $this->rootTeam->id,
    ]);

    DockerCleanupExecution::create([
        'server_id' => $server->id,
        'status' => 'failed',
        'message' => 'Cleanup failed: disk full',
    ]);

    Livewire::test(ScheduledJobs::class)
        ->assertStatus(200)
        ->assertSee('Cleanup');
});

test('filter by type works', function () {
    $this->actingAs($this->rootUser);
    session(['currentTeam' => $this->rootTeam]);

    Livewire::test(ScheduledJobs::class)
        ->set('filterType', 'backup')
        ->assertStatus(200)
        ->set('filterType', 'cleanup')
        ->assertStatus(200)
        ->set('filterType', 'task')
        ->assertStatus(200);
});

test('only failed executions are shown', function () {
    $this->actingAs($this->rootUser);
    session(['currentTeam' => $this->rootTeam]);

    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->rootTeam->id,
        'frequency' => '0 * * * *',
        'database_id' => 1,
        'database_type' => 'App\Models\StandalonePostgresql',
        'enabled' => true,
    ]);

    ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'message' => 'Backup completed successfully',
    ]);

    ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'failed',
        'message' => 'Backup failed: connection refused',
    ]);

    Livewire::test(ScheduledJobs::class)
        ->assertSee('Backup failed: connection refused')
        ->assertDontSee('Backup completed successfully');
});

test('filter by date range works', function () {
    $this->actingAs($this->rootUser);
    session(['currentTeam' => $this->rootTeam]);

    Livewire::test(ScheduledJobs::class)
        ->set('filterDate', 'last_7d')
        ->assertStatus(200)
        ->set('filterDate', 'last_30d')
        ->assertStatus(200)
        ->set('filterDate', 'all')
        ->assertStatus(200);
});

test('scheduler log parser returns empty collection when no logs exist', function () {
    $parser = new SchedulerLogParser;

    $skips = $parser->getRecentSkips();
    expect($skips)->toBeEmpty();

    $runs = $parser->getRecentRuns();
    expect($runs)->toBeEmpty();
})->skip(fn () => file_exists(storage_path('logs/scheduled-'.now()->format('Y-m-d').'.log')), 'Skipped: log file already exists from other tests');

test('scheduler log parser parses skip entries correctly', function () {
    $logPath = storage_path('logs/scheduled-'.now()->format('Y-m-d').'.log');
    $logDir = dirname($logPath);
    if (! is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logLine = '['.now()->format('Y-m-d H:i:s').'] production.INFO: Backup skipped {"type":"backup","skip_reason":"server_not_functional","execution_time":"'.now()->toIso8601String().'","backup_id":1,"team_id":5}';
    file_put_contents($logPath, $logLine."\n");

    $parser = new SchedulerLogParser;
    $skips = $parser->getRecentSkips();

    expect($skips)->toHaveCount(1);
    expect($skips->first()['type'])->toBe('backup');
    expect($skips->first()['reason'])->toBe('server_not_functional');
    expect($skips->first()['team_id'])->toBe(5);

    // Cleanup
    @unlink($logPath);
});

test('scheduler log parser excludes started events from runs', function () {
    $logPath = storage_path('logs/scheduled-test-started-filter.log');
    $logDir = dirname($logPath);
    if (! is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Temporarily rename existing logs so they don't interfere
    $existingLogs = glob(storage_path('logs/scheduled-*.log'));
    $renamed = [];
    foreach ($existingLogs as $log) {
        $tmp = $log.'.bak';
        rename($log, $tmp);
        $renamed[$tmp] = $log;
    }

    $logPath = storage_path('logs/scheduled-'.now()->format('Y-m-d').'.log');
    $lines = [
        '['.now()->format('Y-m-d H:i:s').'] production.INFO: ScheduledJobManager started {}',
        '['.now()->format('Y-m-d H:i:s').'] production.INFO: ScheduledJobManager completed {"duration_ms":74,"dispatched":1,"skipped":13}',
    ];
    file_put_contents($logPath, implode("\n", $lines)."\n");

    $parser = new SchedulerLogParser;
    $runs = $parser->getRecentRuns();

    expect($runs)->toHaveCount(1);
    expect($runs->first()['message'])->toContain('completed');

    // Cleanup
    @unlink($logPath);
    foreach ($renamed as $tmp => $original) {
        rename($tmp, $original);
    }
});

test('scheduler log parser filters by team id', function () {
    $logPath = storage_path('logs/scheduled-'.now()->format('Y-m-d').'.log');
    $logDir = dirname($logPath);
    if (! is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $lines = [
        '['.now()->format('Y-m-d H:i:s').'] production.INFO: Backup skipped {"type":"backup","skip_reason":"server_not_functional","team_id":1}',
        '['.now()->format('Y-m-d H:i:s').'] production.INFO: Backup skipped {"type":"backup","skip_reason":"subscription_unpaid","team_id":2}',
    ];
    file_put_contents($logPath, implode("\n", $lines)."\n");

    $parser = new SchedulerLogParser;

    $allSkips = $parser->getRecentSkips(100);
    expect($allSkips)->toHaveCount(2);

    $team1Skips = $parser->getRecentSkips(100, 1);
    expect($team1Skips)->toHaveCount(1);
    expect($team1Skips->first()['team_id'])->toBe(1);

    // Cleanup
    @unlink($logPath);
});

test('skipped jobs show fallback when resource is deleted', function () {
    $this->actingAs($this->rootUser);
    session(['currentTeam' => $this->rootTeam]);

    $logPath = storage_path('logs/scheduled-'.now()->format('Y-m-d').'.log');
    $logDir = dirname($logPath);
    if (! is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Temporarily rename existing logs so they don't interfere
    $existingLogs = glob(storage_path('logs/scheduled-*.log'));
    $renamed = [];
    foreach ($existingLogs as $log) {
        $tmp = $log.'.bak';
        rename($log, $tmp);
        $renamed[$tmp] = $log;
    }

    $lines = [
        '['.now()->format('Y-m-d H:i:s').'] production.INFO: Task skipped {"type":"task","skip_reason":"application_not_running","task_id":99999,"task_name":"my-cron-job","team_id":0}',
    ];
    file_put_contents($logPath, implode("\n", $lines)."\n");

    Livewire::test(ScheduledJobs::class)
        ->assertStatus(200)
        ->assertSee('my-cron-job')
        ->assertSee('Application not running');

    // Cleanup
    @unlink($logPath);
    foreach ($renamed as $tmp => $original) {
        rename($tmp, $original);
    }
});
