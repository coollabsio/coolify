<?php

use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Support\RemoteProcessCommand;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const BACKUP_QUOTING_PAYLOAD = '../app\' $HOME \'db x';

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Event::fake([BackupCreated::class]);
    Notification::fake();
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create(['name' => 'Acme']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->commands = collect();
    Process::fake(function ($process) {
        $script = remoteProcessScript($process);
        $this->commands->push($script);

        return Process::result(output: match (true) {
            str_contains($script, 'du -b') => '128',
            str_contains($script, '| grep -E') => "POSTGRES_USER=app\nPOSTGRES_DB=app",
            default => '',
        });
    });
});

function backupQuotingSchedule(mixed $database, Team $team, array $overrides = []): ScheduledDatabaseBackup
{
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => 'daily',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        ...$overrides,
    ])->save();

    return $backup->fresh();
}

function backupQuotingService(Server $server, StandaloneDocker $destination, Environment $environment): Service
{
    return Service::factory()->create([
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);
}

it('quotes the container name, the database name, and the backup paths in the backup commands', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['status' => 'running:healthy']);
    $backup = backupQuotingSchedule($database, $this->team, ['databases_to_backup' => BACKUP_QUOTING_PAYLOAD]);

    (new DatabaseBackupJob($backup))->handle();

    $file = ScheduledDatabaseBackupExecution::query()->sole()->filename;
    $dumpCommand = "docker exec '{$database->uuid}' pg_dump --format=custom --username 'postgres' "
        .escapeshellarg(BACKUP_QUOTING_PAYLOAD)." > '{$file}'";

    expect($this->commands->implode("\n"))
        ->toContain("\nmkdir -p '".dirname($file)."'\n")
        ->toContain("\nsh -c ".escapeshellarg($dumpCommand)."\n")
        ->toContain("\ndu -b '{$file}' | cut -f1")
        ->not->toContain(' '.BACKUP_QUOTING_PAYLOAD.' ')
        ->not->toContain("docker exec {$database->uuid} ");
});

it('quotes the backup file in the mount of the S3 upload container', function () {
    $storage = S3Storage::create([
        'name' => 'Destination',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://8.8.8.8',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->zeroOrMoreTimes()->andReturn([]);
    $sshDisk = Storage::fake('ssh-keys');
    Storage::shouldReceive('disk')->with('ssh-keys')->andReturn($sshDisk);
    Storage::shouldReceive('build')->zeroOrMoreTimes()->andReturn($disk);
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['status' => 'running:healthy']);
    $backup = backupQuotingSchedule($database, $this->team, [
        'save_s3' => true,
        's3_storage_id' => $storage->id,
        'databases_to_backup' => 'app',
    ]);
    $backup->syncS3Storages([$storage->id]);

    (new DatabaseBackupJob($backup->fresh()))->handle();

    $file = ScheduledDatabaseBackupExecution::query()->sole()->filename;
    expect($this->commands->first(fn (string $command) => str_contains($command, 'docker run -d')))
        ->toContain(' -v '.escapeshellarg("{$file}:{$file}:ro").' ')
        ->not->toContain(" -v {$file}:");
});

it('backs up a service database with a supported container name', function () {
    $service = backupQuotingService($this->server, $this->destination, $this->environment);
    $database = ServiceDatabase::create(['service_id' => $service->id, 'name' => 'db-name_service.1', 'image' => 'postgres:16', 'status' => 'running:healthy']);

    (new DatabaseBackupJob(backupQuotingSchedule($database, $this->team)))->handle();

    expect($this->commands->implode("\n"))->toContain("docker exec 'db-name_service.1-{$service->uuid}' env")
        ->and(ScheduledDatabaseBackupExecution::query()->sole()->status)->toBe('success');
});

it('refuses to back up a service database whose container name is not supported', function (string $name) {
    $service = backupQuotingService($this->server, $this->destination, $this->environment);
    $database = ServiceDatabase::create(['service_id' => $service->id, 'name' => 'db', 'image' => 'postgres:16', 'status' => 'running:healthy']);
    ServiceDatabase::query()->whereKey($database->id)->update(['name' => $name]);

    expect(fn () => (new DatabaseBackupJob(backupQuotingSchedule($database, $this->team)))->handle())
        ->toThrow(Exception::class, 'Invalid database container name.');
    expect($this->commands->filter(fn (string $command) => str_contains($command, 'docker exec')))->toBeEmpty();
})->with([
    'space' => ['db name'],
    'newline' => ["db\nname"],
    'shell payload' => ["db'; touch /tmp/pwn; '"],
]);

it('quotes the container name in the restart command of a service database and a service application', function () {
    Queue::fake();
    $service = backupQuotingService($this->server, $this->destination, $this->environment);
    $database = ServiceDatabase::create(['service_id' => $service->id, 'name' => 'db', 'image' => 'postgres:16']);
    $application = ServiceApplication::create(['service_id' => $service->id, 'name' => 'app']);
    ServiceDatabase::query()->whereKey($database->id)->update(['name' => "db'; touch /tmp/pwn; '"]);
    ServiceApplication::query()->whereKey($application->id)->update(['name' => 'app$(id)']);

    $database->fresh()->restart();
    $application->fresh()->restart();

    $databaseContainer = escapeshellarg("db'; touch /tmp/pwn; '-{$service->uuid}");
    expect(RemoteProcessCommand::read(Activity::query()->latest('id')->firstOrFail()))->toBe("docker restart {$databaseContainer}")
        ->and($this->commands->implode("\n"))->toContain("\ndocker restart 'app\$(id)-{$service->uuid}'")
        ->not->toContain('docker restart app$(id)');
});

it('replaces shell and path separators in the database part of the backup file name', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, ['status' => 'running:healthy']);
    $backup = backupQuotingSchedule($database, $this->team, ['databases_to_backup' => 'safe_db-1,../db name']);

    (new DatabaseBackupJob($backup))->handle();

    $files = ScheduledDatabaseBackupExecution::query()->orderBy('id')->pluck('filename')->map(fn (string $file) => basename($file));
    expect($files[0])->toStartWith('pg-dump-safe_db-1-')
        ->and($files[1])->toStartWith('pg-dump-..-db-name-')
        ->and($files->implode(''))->not->toContain(' ');
});
