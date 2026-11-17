<?php

use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

function localRetentionBackup(array $attributes = []): ScheduledDatabaseBackup
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination, ['status' => 'running:healthy']);
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => 'daily',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        ...$attributes,
    ])->save();

    return $backup->fresh();
}

function localRetentionExecution(ScheduledDatabaseBackup $backup, string $name, string $database, int $hoursAgo, int $size = 1024): ScheduledDatabaseBackupExecution
{
    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => $name,
        'database_name' => $database,
        'filename' => "/backup/{$name}.dmp",
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'size' => $size,
        'local_storage_deleted' => false,
    ]);
    ScheduledDatabaseBackupExecution::whereKey($execution->id)->update(['created_at' => now()->subHours($hoursAgo)]);

    return $execution;
}

/**
 * Records the remote scripts and fails the scripts that contain $failWhen.
 */
function fakeLocalRetentionServer(?string $failWhen = null): Collection
{
    $scripts = collect();
    Process::fake(function ($process) use ($scripts, $failWhen) {
        $script = remoteProcessScript($process);
        $scripts->push($script);
        if ($failWhen !== null && str_contains($script, $failWhen)) {
            return Process::result(errorOutput: 'rm: cannot remove: Permission denied', exitCode: 1);
        }

        return Process::result(output: str_contains($script, 'du -b') ? '128' : '');
    });

    return $scripts;
}

it('keeps the configured number of local backups for each database of a schedule', function () {
    $backup = localRetentionBackup(['database_backup_retention_amount_locally' => 2]);
    foreach (['app', 'auth', 'billing'] as $database) {
        foreach ([1, 2, 3] as $day) {
            localRetentionExecution($backup, "{$database}-{$day}", $database, $day * 24);
        }
    }
    fakeLocalRetentionServer();

    removeOldBackups($backup);

    expect($backup->executions()->pluck('uuid')->sort()->values()->all())
        ->toBe(['app-1', 'app-2', 'auth-1', 'auth-2', 'billing-1', 'billing-2']);
});

it('keeps the newest backup of each database when the storage limit is reached', function () {
    $backup = localRetentionBackup(['database_backup_retention_max_storage_locally' => 1]);
    $gigabyte = 1024 ** 3;
    localRetentionExecution($backup, 'app-new', 'app', 1, $gigabyte);
    localRetentionExecution($backup, 'auth-new', 'auth', 1, $gigabyte);
    localRetentionExecution($backup, 'app-old', 'app', 24, $gigabyte);
    localRetentionExecution($backup, 'auth-old', 'auth', 25, $gigabyte);
    fakeLocalRetentionServer();

    removeOldBackups($backup);

    expect($backup->executions()->pluck('uuid')->sort()->values()->all())
        ->toBe(['app-new', 'app-old', 'auth-new']);
});

it('keeps the executions of local files that the server failed to delete', function () {
    $backup = localRetentionBackup(['database_backup_retention_amount_locally' => 1]);
    localRetentionExecution($backup, 'new', 'app', 1);
    $old = localRetentionExecution($backup, 'old', 'app', 24);
    fakeLocalRetentionServer(failWhen: 'rm -f');

    expect(fn () => removeOldBackups($backup))->toThrow(RuntimeException::class);

    expect($old->fresh())->not->toBeNull()
        ->and($old->fresh()->local_storage_deleted)->toBeFalse();
});

it('applies retention when a later database of the run fails', function () {
    $backup = localRetentionBackup([
        'databases_to_backup' => 'app,broken',
        'database_backup_retention_amount_locally' => 1,
    ]);
    localRetentionExecution($backup, 'old', 'app', 24);
    $scripts = fakeLocalRetentionServer(failWhen: 'broken');

    (new DatabaseBackupJob($backup))->handle();

    expect($scripts->contains(fn (string $script) => str_contains($script, "rm -f '/backup/old.dmp'")))->toBeTrue()
        ->and($backup->executions()->where('uuid', 'old')->exists())->toBeFalse();
});
