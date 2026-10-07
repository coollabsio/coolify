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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);
    Notification::fake();
});

/**
 * Runs a MySQL backup on a server with the given SSH user and returns the remote script of the dump command.
 */
function backupDirectoryDumpScript(string $sshUser): string
{
    $team = Team::factory()->create(['name' => 'Acme']);
    $server = Server::factory()->create(['team_id' => $team->id, 'user' => $sshUser]);
    $server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = create_standalone_mysql($environment->id, StandaloneDocker::where('server_id', $server->id)->firstOrFail(), [
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
    ]);
    $commands = new Collection;
    Process::fake(function ($process) use ($commands) {
        $commands->push($process->command);

        return Process::result(output: str_contains($process->command, 'du -b') ? '100' : '');
    });

    (new DatabaseBackupJob($backup))->handle();

    return $commands->first(fn (string $command) => str_contains($command, 'mysqldump'));
}

it('gives the backup directory to the ssh user and closes it to other users on a non-root server', function () {
    $script = backupDirectoryDumpScript('ubuntu');

    expect($script)->toMatch("~^sudo mkdir -p '(/data/coolify/backups/databases/acme-\\d+/[^']+)'\\nsudo find '\\1' -user root -exec chown [^{]*ubuntu:ubuntu \\{\\} \\+ && sudo chmod o-rwx '\\1'\\n.*mysqldump~m");
});

it('leaves the backup directory command unchanged on a root server', function () {
    $script = backupDirectoryDumpScript('root');

    expect($script)->toMatch("~^mkdir -p '/data/coolify/backups/databases/acme-\\d+/[^']+'\\n.*mysqldump~m")
        ->and($script)->not->toContain('chmod o-rwx');
});
