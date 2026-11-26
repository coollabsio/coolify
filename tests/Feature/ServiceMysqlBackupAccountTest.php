<?php

use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
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
    Event::fake([BackupCreated::class]);
    Notification::fake();
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => Environment::factory()->create(['project_id' => $project->id])->id,
    ]);
});

/**
 * Backs up a service database whose container has the given environment and accepts only the
 * given login, and returns the dump command.
 */
function serviceMysqlBackupDumpCommand(Service $service, Team $team, string $image, string $env, ?string $acceptedLogin): string
{
    $database = ServiceDatabase::create(['service_id' => $service->id, 'name' => 'db', 'image' => $image, 'status' => 'running:healthy']);
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => 'daily',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
    ])->save();

    $commands = collect();
    Process::fake(function ($process) use ($commands, $env, $acceptedLogin) {
        // Undo the quoting of the sh -c wrapper, so the assertions read the command as written.
        $script = str_replace("'\\''", "'", remoteProcessScript($process));
        $commands->push($script);

        return Process::result(output: match (true) {
            str_contains($script, 'du -b') => '128',
            str_contains($script, 'SELECT 1') => $acceptedLogin !== null && str_contains($script, " {$acceptedLogin} -N -e") ? 'ok' : '',
            str_contains($script, ' env') => $env,
            default => '',
        });
    });

    (new DatabaseBackupJob($backup->fresh()))->handle();

    return $commands->first(fn (string $command) => str_contains($command, 'dump') && str_contains($command, '> '));
}

it('backs up a linuxserver.io MariaDB with the root account that has no password', function () {
    $env = "MYSQL_ROOT_PASSWORD=rootpass\nMYSQL_DATABASE=bookstack\nMYSQL_USER=bookstack\nMYSQL_PASSWORD=userpass";

    $dump = serviceMysqlBackupDumpCommand($this->service, $this->team, 'lscr.io/linuxserver/mariadb:latest', $env, 'mariadb -u root');

    expect($dump)->toContain("mariadb-dump -u root 'bookstack' >")
        ->not->toContain('rootpass');
});

it('backs up a MySQL service with a random root password with the application user', function () {
    $env = "MYSQL_RANDOM_ROOT_PASSWORD=true\nMYSQL_DATABASE=app\nMYSQL_USER=app\nMYSQL_PASSWORD=userpass";

    $dump = serviceMysqlBackupDumpCommand($this->service, $this->team, 'mysql:8', $env, "mysql -u 'app' -p'userpass'");

    expect($dump)->toContain("mysqldump -u 'app' -p'userpass' --no-tablespaces 'app' >");
});

it('backs up a MariaDB service with the root password when the database accepts it', function () {
    $env = "MARIADB_ROOT_PASSWORD=rootpass\nMARIADB_DATABASE=app\nMARIADB_USER=app\nMARIADB_PASSWORD=userpass";

    $dump = serviceMysqlBackupDumpCommand($this->service, $this->team, 'mariadb:11', $env, "mariadb -u root -p'rootpass'");

    expect($dump)->toContain("mariadb-dump -u root -p'rootpass' 'app' >");
});

it('uses the root password when the database accepts no account, so the dump shows the database error', function () {
    $env = "MYSQL_ROOT_PASSWORD=rootpass\nMYSQL_DATABASE=app";

    $dump = serviceMysqlBackupDumpCommand($this->service, $this->team, 'mysql:8', $env, null);

    expect($dump)->toContain("mysqldump -u root -p'rootpass' 'app' >");
});
