<?php

use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMongodb;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.retry_base_delay' => 0]);
    Event::fake([BackupCreated::class]);
    Notification::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings->update(['smtp_enabled' => true, 'backup_failure_email_notifications' => true]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $database = create_standalone_mongodb($environment->id, StandaloneDocker::where('server_id', $server->id)->firstOrFail(), [
        'status' => 'running:healthy',
    ]);
    $this->backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => StandaloneMongodb::class,
        'database_id' => $database->id,
        'team_id' => $this->team->id,
        'databases_to_backup' => null,
    ]);
});

/**
 * mongodump writes its progress to stderr. When the dump process or the database container is killed,
 * the output stops after the last progress line and there is no "Failed:" line (issue #11549).
 */
function interruptedMongodump(int $exitCode): void
{
    Process::fake(function ($process) use ($exitCode) {
        if (str_contains($process->command, 'mongodump')) {
            return Process::result(
                errorOutput: "2026-08-29T15:44:00.555+0000\tdone dumping `evolution.leaverequests` (0 documents)\n2026-08-29T15:44:00.555+0000\twriting `evolution.printagents` to `archive on stdout`",
                exitCode: $exitCode,
            );
        }

        return Process::result(output: '');
    });
}

it('tells why a dump stopped without an error message', function (int $exitCode, string $reason) {
    interruptedMongodump($exitCode);

    (new DatabaseBackupJob($this->backup))->handle();

    $execution = $this->backup->executions()->latest('id')->firstOrFail();
    expect($execution->status)->toBe('failed')
        ->and($execution->message)->toStartWith($reason)
        ->and($execution->message)->toContain('writing `evolution.printagents` to `archive on stdout`');
    Notification::assertSentTo($this->team, BackupFailed::class, fn (BackupFailed $notification) => str_starts_with((string) $notification->output, $reason));
})->with([
    'dump or database killed' => [137, 'The backup stopped with exit code 137: the dump process or the database container was killed (SIGKILL).'],
    'dump terminated' => [143, 'The backup stopped with exit code 143: the dump process or the database container was terminated (SIGTERM)'],
    'SSH connection lost' => [255, 'The backup stopped with exit code 255: the SSH connection to the server closed.'],
]);

it('keeps the error of a dump that reports its own failure', function () {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'mongodump')) {
            return Process::result(errorOutput: 'Failed: error connecting to db server: server returned error on SASL authentication step', exitCode: 1);
        }

        return Process::result(output: '');
    });

    (new DatabaseBackupJob($this->backup))->handle();

    expect($this->backup->executions()->latest('id')->firstOrFail()->message)
        ->toBe('Failed: error connecting to db server: server returned error on SASL authentication step');
});
