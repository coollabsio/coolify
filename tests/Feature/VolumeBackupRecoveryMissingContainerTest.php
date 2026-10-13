<?php

use App\Jobs\ScheduledJobManager;
use App\Jobs\VolumeBackupJob;
use App\Jobs\VolumeBackupRecoveryJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

const MISSING_CONTAINER_GONE = 'aaaaaaaaaaaa';
const MISSING_CONTAINER_BROKEN = 'bbbbbbbbbbbb';
const MISSING_CONTAINER_STOPPED = 'cccccccccccc';

beforeEach(function () {
    Server::flushIdentityMap();
    Notification::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['broadcasting.default' => 'null', 'constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->fakeDockerDir = sys_get_temp_dir().'/prr-fix-fake-docker-'.uniqid();
    File::ensureDirectoryExists($this->fakeDockerDir);
    $this->dockerLog = $this->fakeDockerDir.'/calls.log';
    File::put($this->fakeDockerDir.'/docker', <<<'SH'
#!/bin/sh
echo "$*" >> "$(dirname "$0")/calls.log"
case "$1" in
  inspect)
    case "$4" in
      aaaaaaaaaaaa) echo "Error: no such object: $4" >&2; exit 1 ;;
      *) echo false ;;
    esac ;;
  start)
    case "$2" in
      bbbbbbbbbbbb) echo "Error response from daemon: cannot start" >&2; exit 1 ;;
    esac ;;
esac
SH);
    chmod($this->fakeDockerDir.'/docker', 0755);
});

afterEach(function () {
    File::deleteDirectory($this->fakeDockerDir);
    foreach (ScheduledVolumeBackupExecution::query()->get() as $execution) {
        File::delete([VolumeBackupRecoveryJob::stateFile($execution), VolumeBackupRecoveryJob::stateFile($execution).'.remaining']);
    }
    Carbon::setTestNow();
});

/**
 * @return array{0: Server, 1: ScheduledVolumeBackup}
 */
function missingContainerRecoveryBackup(string $user): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
        'user' => $user,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => '* * * * *',
        'enabled' => true,
        'save_s3' => false,
        'stop_during_backup' => true,
    ]);

    return [Server::find($server->id), $backup];
}

/**
 * Runs the remote container recovery script with a local sh and a fake docker binary, as the server would.
 */
function fakeMissingContainerRemote(string $fakeDockerDir): void
{
    Process::fake(function ($process) use ($fakeDockerDir) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $lines = explode(PHP_EOL, $command);
        $remote = implode(PHP_EOL, array_slice($lines, 1, -1));
        if (str_starts_with($remote, 'sudo ')) {
            $remote = substr($remote, strlen('sudo '));
        }
        if (! str_starts_with($remote, 'sh -c ') && ! str_starts_with($remote, 'cat ')) {
            return Process::result(output: '');
        }

        $result = new SymfonyProcess(['sh', '-c', $remote], null, ['PATH' => $fakeDockerDir.':'.getenv('PATH')]);
        $result->run();

        return Process::result(output: $result->getOutput(), errorOutput: $result->getErrorOutput(), exitCode: $result->getExitCode());
    });
}

it('treats stopped containers that no longer exist as recovered and does not block the schedule', function (string $user) {
    Carbon::setTestNow('2026-10-03 12:00:00');
    config(['constants.coolify.self_hosted' => true]);
    [$server, $backup] = missingContainerRecoveryBackup($user);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'failed',
        'stop_recovery_pending' => true,
    ]);
    File::put(VolumeBackupRecoveryJob::stateFile($execution), MISSING_CONTAINER_GONE."\n".MISSING_CONTAINER_STOPPED."\n");
    fakeMissingContainerRemote($this->fakeDockerDir);

    VolumeBackupRecoveryJob::recoverWithBounds($execution);

    $execution->refresh();
    expect($execution->stop_recovery_pending)->toBeFalse()
        ->and($execution->recovery_needs_attention)->toBeFalse()
        ->and(File::exists(VolumeBackupRecoveryJob::stateFile($execution)))->toBeFalse()
        ->and(File::get($this->dockerLog))->toContain('start '.MISSING_CONTAINER_STOPPED);

    Queue::fake();
    Cache::forget("scheduled-volume-backup:{$backup->id}");
    (new ScheduledJobManager)->handle();
    Queue::assertPushed(VolumeBackupJob::class, fn (VolumeBackupJob $job) => $job->backup->is($backup));
})->with(['root user' => ['root'], 'non-root user' => ['ubuntu']]);

it('still needs attention when an existing container fails to start', function (string $user) {
    [, $backup] = missingContainerRecoveryBackup($user);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'failed',
        'stop_recovery_pending' => true,
    ]);
    File::put(VolumeBackupRecoveryJob::stateFile($execution), MISSING_CONTAINER_GONE."\n".MISSING_CONTAINER_BROKEN."\n");
    fakeMissingContainerRemote($this->fakeDockerDir);

    VolumeBackupRecoveryJob::recoverWithBounds($execution);

    $execution->refresh();
    expect($execution->stop_recovery_pending)->toBeTrue()
        ->and($execution->recovery_needs_attention)->toBeTrue()
        ->and(trim(File::get(VolumeBackupRecoveryJob::stateFile($execution))))->toBe(MISSING_CONTAINER_BROKEN);
})->with(['root user' => ['root'], 'non-root user' => ['ubuntu']]);
