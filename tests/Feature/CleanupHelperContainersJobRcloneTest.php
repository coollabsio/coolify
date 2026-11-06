<?php

use App\Enums\ProcessStatus;
use App\Jobs\CleanupHelperContainersJob;
use App\Models\PrivateKey;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->team = Team::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<int, array{0: string, 1: string}>  $containers  name and creation time
 */
function fakeRcloneContainers(array $containers, array &$scripts): void
{
    $lines = collect($containers)->map(fn (array $container, int $index): string => json_encode([
        'ID' => 'id'.$index,
        'Names' => $container[0],
        'Image' => 'docker.io/rclone/rclone:1.75.2',
        'CreatedAt' => $container[1].' +0000 UTC',
    ]))->implode("\n");

    Process::fake(function (PendingProcess $process) use ($lines, &$scripts) {
        $scripts[] = $script = remoteProcessScript($process);

        return Process::result(str_contains($script, 'label=coolify.type=rclone') ? $lines : '[]');
    });
}

it('removes rclone containers of ended jobs, and keeps those of running jobs', function (string $user, string $docker) {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'user' => $user,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $databaseBackup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_type' => StandalonePostgresql::class,
        'database_id' => 1,
        'team_id' => $this->team->id,
    ]);
    $databaseBackup->executions()->create(['uuid' => 'runningdb', 'status' => 'running']);
    $databaseBackup->executions()->create(['uuid' => 'endeddb', 'status' => 'failed']);
    $volumeBackup = ScheduledVolumeBackup::create([
        'backupable_type' => StandalonePostgresql::class,
        'backupable_id' => 1,
        'team_id' => $this->team->id,
        'frequency' => 'daily',
    ]);
    $volumeExecution = $volumeBackup->executions()->create(['status' => 'running']);
    $importOperation = (string) Str::uuid();
    Activity::create(['log_name' => 'default', 'description' => 'import', 'properties' => [
        'operation' => 'database_import',
        'operation_uuid' => $importOperation,
        'status' => ProcessStatus::IN_PROGRESS->value,
    ]]);
    $scripts = [];
    fakeRcloneContainers([
        ['backup-of-runningdb', '2026-11-01 10:00:00'],
        ['backup-of-endeddb', '2026-11-01 10:00:00'],
        ["volume-upload-{$volumeExecution->uuid}", '2026-11-01 10:00:00'],
        ["s3-restore-{$importOperation}", '2026-11-01 10:00:00'],
        ['s3-restore-'.Str::uuid(), '2026-11-01 10:00:00'],
        ['backup-of-juststarted', '2026-11-01 11:55:00'],
    ], $scripts);

    (new CleanupHelperContainersJob($server))->handle();

    $removed = collect(range(0, 5))->filter(fn (int $index): bool => collect($scripts)
        ->contains(fn (string $script): bool => str_contains($script, "{$docker} container rm -f 'id{$index}'")))->values()->all();
    expect($removed)->toBe([1, 4]);
})->with([
    'root' => ['root', 'docker'],
    'non-root' => ['ubuntu', 'sudo docker'],
]);

it('keeps the helper containers of running backups and imports and removes orphaned backup uploads', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_type' => StandalonePostgresql::class,
        'database_id' => 1,
        'team_id' => $this->team->id,
    ])->executions()->create(['uuid' => 'runningdb', 'status' => 'running']);
    $volumeExecution = ScheduledVolumeBackup::create([
        'backupable_type' => StandalonePostgresql::class,
        'backupable_id' => 1,
        'team_id' => $this->team->id,
        'frequency' => 'daily',
    ])->executions()->create(['status' => 'running']);
    $importOperation = (string) Str::uuid();
    Activity::create(['log_name' => 'default', 'description' => 'import', 'properties' => [
        'operation' => 'database_import',
        'operation_uuid' => $importOperation,
        'status' => ProcessStatus::QUEUED->value,
    ]]);
    $scripts = [];
    Process::fake(function (PendingProcess $process) use (&$scripts, $volumeExecution, $importOperation) {
        $scripts[] = $script = remoteProcessScript($process);

        return Process::result(str_contains($script, 'coolify-helper') ? json_encode([
            ['ID' => 'running', 'Names' => 'backup-of-runningdb', 'Image' => 'ghcr.io/coollabsio/coolify-helper:1.0.13'],
            ['ID' => 'running', 'Names' => "volume-backup-{$volumeExecution->uuid}", 'Image' => 'ghcr.io/coollabsio/coolify-helper:1.0.13'],
            ['ID' => 'running', 'Names' => "backup-decompress-{$importOperation}", 'Image' => 'ghcr.io/coollabsio/coolify-helper:1.0.13'],
            ['ID' => 'orphan', 'Names' => 'backup-of-endeddb', 'Image' => 'ghcr.io/coollabsio/coolify-helper:1.0.13'],
        ]) : '');
    });

    (new CleanupHelperContainersJob($server))->handle();

    $removals = collect($scripts)->filter(fn (string $script): bool => str_contains($script, 'docker container rm -f'))->values();
    expect($removals)->toHaveCount(1)
        ->and($removals->first())->toContain("docker container rm -f 'orphan'");
});
