<?php

use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
});

/**
 * The exception Process::run() throws when a command runs longer than its timeout. Symfony puts the
 * complete command line into the message.
 */
function backupRedactionTimeoutException(string $command, int $timeout): ProcessTimedOutException
{
    $process = SymfonyProcess::fromShellCommandline($command);
    $process->setTimeout($timeout);

    return new ProcessTimedOutException(new SymfonyTimeoutException($process, SymfonyTimeoutException::TYPE_GENERAL), new ProcessResult($process));
}

function backupRedactionServer(Team $team): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    return $server->fresh();
}

it('does not put the command into the error when a remote command times out', function () {
    config(['constants.ssh.max_retries' => 3, 'constants.ssh.retry_base_delay' => 0]);
    $server = backupRedactionServer(Team::factory()->create());
    $attempts = 0;
    Process::fake(function ($process) use (&$attempts) {
        $attempts++;

        throw backupRedactionTimeoutException($process->command, 7200);
    });

    try {
        instant_remote_process(["docker exec mysql mysqldump -u root -p'TopSecretPass' app > /tmp/x"], $server, timeout: 7200, disableMultiplexing: true);
        $this->fail('The timeout was not reported.');
    } catch (Throwable $exception) {
        expect($exception->getMessage())->toBe('The command on the server timed out after 7200 seconds.')
            ->and($exception->getPrevious())->toBeNull();
    }

    expect($attempts)->toBe(1);
});

it('does not put database credentials into the backup execution or the failure notification when the dump times out', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Notification::fake();
    $team = Team::factory()->create();
    $server = backupRedactionServer($team);
    $team->emailNotificationSettings->update(['smtp_enabled' => true, 'backup_failure_email_notifications' => true]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = create_standalone_mysql($environment->id, StandaloneDocker::where('server_id', $server->id)->firstOrFail(), [
        'mysql_root_password' => 'TopSecretPass',
        'mysql_database' => 'app',
        'status' => 'running:healthy',
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => StandaloneMysql::class,
        'database_id' => $database->id,
        'team_id' => $team->id,
        'databases_to_backup' => 'app',
        'timeout' => 600,
    ]);
    Process::fake(function ($process) {
        if (str_contains($process->command, 'mysqldump')) {
            throw backupRedactionTimeoutException($process->command, 600);
        }

        return Process::result(output: '');
    });

    try {
        (new DatabaseBackupJob($backup))->handle();
    } catch (Throwable) {
        // The job reports the failure after it records the execution.
    }

    $execution = $backup->executions()->latest('id')->firstOrFail();
    expect($execution->status)->toBe('failed')
        ->and($execution->message)->toBe('The command on the server timed out after 600 seconds.');
    Notification::assertSentTo($team, BackupFailed::class, fn (BackupFailed $notification) => ! str_contains((string) $notification->output, 'TopSecretPass')
        && str_contains((string) $notification->output, 'timed out after 600 seconds'));
});
