<?php

use App\Jobs\DeleteFileDownloadJob;
use App\Jobs\PrepareFileDownloadJob;
use App\Livewire\Project\Database\BackupExecutions;
use App\Livewire\Project\Service\BackupExecutions as ServiceBackupExecutions;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\FileDownloadStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const BACKUP_S3_DOWNLOAD_FILE = '/data/coolify/backups/databases/orders/pg-dump-orders-1.dmp';

beforeEach(function () {
    config(['app.maintenance.driver' => 'file', 'constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    Server::flushIdentityMap();
    Once::flush();
    InstanceSettings::forceCreate(['id' => 0]);
    Team::factory()->create(['id' => 0]);
    $this->downloadStorage = S3Storage::create([
        'name' => 'Downloads',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'downloads',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => 0,
    ]);
    InstanceSettings::find(0)->update(['file_download_s3_storage_id' => $this->downloadStorage->id]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->database = StandalonePostgresql::create([
        'name' => 'orders-db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db',
        'image' => 'postgres:17', 'environment_id' => $environment->id,
        'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(),
    ]);
    $this->backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'database_id' => $this->database->id,
        'database_type' => $this->database->getMorphClass(),
    ]);
    $this->execution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $this->backup->id,
        'status' => 'success',
        'filename' => BACKUP_S3_DOWNLOAD_FILE,
    ]);
    $this->service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);

    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->user->teams()->attach($this->otherTeam, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Bus::fake([PrepareFileDownloadJob::class]);
});

function fakeBackupFileSize(int|string $size): void
{
    Process::fake(['*' => Process::result(output: (string) $size)]);
}

it('uploads a database backup through S3 for an admin of its team after a team switch', function () {
    fakeBackupFileSize(1024);
    $component = Livewire::test(BackupExecutions::class, ['backup' => $this->backup]);
    session(['currentTeam' => $this->otherTeam]);

    $component->call('download_file', $this->execution->id)
        ->assertDispatched('info')
        ->assertNotDispatched('open-backup-download')
        ->assertCount('pendingDownloads', 1);

    Bus::assertDispatched(PrepareFileDownloadJob::class, fn (PrepareFileDownloadJob $job) => $job->container === null
        && $job->path === BACKUP_S3_DOWNLOAD_FILE
        && $job->serverId === $this->server->id
        && $job->downloadName === 'pg-dump-orders-1.dmp'
        && $job->s3StorageId === $this->downloadStorage->id
        && $job->userId === $this->user->id);
});

it('streams through the download route when no download storage is set', function () {
    InstanceSettings::find(0)->update(['file_download_s3_storage_id' => null]);
    Once::flush();
    Process::fake();

    Livewire::test(BackupExecutions::class, ['backup' => $this->backup])
        ->call('download_file', $this->execution->id)
        ->assertDispatched('open-backup-download', url: route('download.backup', $this->execution->id));

    Process::assertNothingRan();
    Bus::assertNotDispatched(PrepareFileDownloadJob::class);
});

it('streams through the download route when the backup is larger than one S3 upload', function () {
    fakeBackupFileSize(FileDownloadStorageService::MAX_SIZE + 1);

    Livewire::test(BackupExecutions::class, ['backup' => $this->backup])
        ->call('download_file', $this->execution->id)
        ->assertDispatched('open-backup-download', url: route('download.backup', $this->execution->id));

    Bus::assertNotDispatched(PrepareFileDownloadJob::class);
});

it('lets the download route answer when the backup file is not on the server', function () {
    fakeBackupFileSize('');

    Livewire::test(BackupExecutions::class, ['backup' => $this->backup])
        ->call('download_file', $this->execution->id)
        ->assertDispatched('open-backup-download', url: route('download.backup', $this->execution->id));

    Bus::assertNotDispatched(PrepareFileDownloadJob::class);
});

it('refuses team members', function () {
    Process::fake();
    $this->user->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
    $this->user->unsetRelation('teams');
    $component = Livewire::test(BackupExecutions::class, ['backup' => $this->backup]);

    $component->call('download_file', $this->execution->id)
        ->assertNotDispatched('open-backup-download');

    Process::assertNothingRan();
    Bus::assertNotDispatched(PrepareFileDownloadJob::class);
});

it('does not download an execution of another backup', function () {
    Process::fake();
    $foreignBackup = ScheduledDatabaseBackup::create([
        'team_id' => $this->otherTeam->id,
        'frequency' => 'daily',
        'database_id' => $this->database->id + 1000,
        'database_type' => $this->database->getMorphClass(),
    ]);
    $foreignExecution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $foreignBackup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/databases/other/secret.dmp',
    ]);

    Livewire::test(BackupExecutions::class, ['backup' => $this->backup])
        ->call('download_file', $foreignExecution->id)
        ->assertDispatched('error', 'Backup execution not found.')
        ->assertNotDispatched('open-backup-download');

    Process::assertNothingRan();
    Bus::assertNotDispatched(PrepareFileDownloadJob::class);
});

it('uploads a volume backup through S3 and refuses members', function () {
    fakeBackupFileSize(2048);
    $application = Application::factory()->create([
        'environment_id' => $this->database->environment_id,
        'destination_id' => $this->database->destination_id,
        'destination_type' => $this->database->destination_type,
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    $backup = $volume->scheduledBackups()->create(['team_id' => $this->team->id, 'frequency' => 'daily']);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/app/archive.tar.gz',
    ]);
    $component = Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application]);
    session(['currentTeam' => $this->otherTeam]);

    $component->call('downloadBackup', $execution->id)->assertCount('pendingDownloads', 1);
    Bus::assertDispatched(PrepareFileDownloadJob::class, fn (PrepareFileDownloadJob $job) => $job->container === null
        && $job->path === '/data/coolify/backups/volumes/app/archive.tar.gz'
        && $job->serverId === $this->server->id);

    $this->user->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
    $this->user->unsetRelation('teams');
    $component->call('downloadBackup', $execution->id)->assertForbidden();
    Bus::assertDispatchedTimes(PrepareFileDownloadJob::class, 1);
});

it('uploads a service database backup through S3', function () {
    fakeBackupFileSize(1024);
    $serviceDatabase = ServiceDatabase::create([
        'service_id' => $this->service->id,
        'name' => 'service-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'database_id' => $serviceDatabase->id,
        'database_type' => $serviceDatabase->getMorphClass(),
    ]);
    $execution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/service/db.dmp',
    ]);

    Livewire::test(ServiceBackupExecutions::class, ['service' => $this->service])
        ->call('downloadExecution', $execution->uuid)
        ->assertCount('pendingDownloads', 1);

    Bus::assertDispatched(PrepareFileDownloadJob::class, fn (PrepareFileDownloadJob $job) => $job->container === null
        && $job->path === '/data/coolify/backups/service/db.dmp');
});

it('opens a finished link only for the user who asked for it', function () {
    fakeBackupFileSize(1024);
    $component = Livewire::test(BackupExecutions::class, ['backup' => $this->backup])
        ->call('download_file', $this->execution->id);
    $downloadId = $component->get('pendingDownloads')[0];

    Cache::put(PrepareFileDownloadJob::cacheKey($downloadId), ['user_id' => $this->user->id + 1, 'status' => 'ready', 'url' => 'https://s3.example.com/other']);
    $component->call('checkDownloads')->assertNotDispatched('open-backup-download')->assertCount('pendingDownloads', 1);

    Cache::put(PrepareFileDownloadJob::cacheKey($downloadId), ['user_id' => $this->user->id, 'status' => 'ready', 'url' => 'https://s3.example.com/get']);
    $component->call('checkDownloads')
        ->assertDispatched('open-backup-download', url: 'https://s3.example.com/get')
        ->assertCount('pendingDownloads', 0);
});

it('uploads the backup file from the server host with curl', function () {
    Bus::fake([DeleteFileDownloadJob::class]);
    Process::fake(['*' => Process::result(output: '')]);
    $downloads = Mockery::mock(FileDownloadStorageService::class);
    $downloads->shouldReceive('uploadUrl')->andReturn(['url' => 'https://s3.example.com/put?X-Amz-Signature=abc&x=1', 'headers' => ['Content-Disposition' => 'attachment']]);
    $downloads->shouldReceive('downloadUrl')->andReturn('https://s3.example.com/get');

    (new PrepareFileDownloadJob('dl-host', $this->user->id, $this->server->id, null, BACKUP_S3_DOWNLOAD_FILE, false, 'pg.dmp', $this->downloadStorage->id))->handle($downloads);

    expect(Cache::get(PrepareFileDownloadJob::cacheKey('dl-host')))->toMatchArray(['status' => 'ready', 'url' => 'https://s3.example.com/get']);
    Process::assertRan(fn ($process) => str_contains($process->command, 'curl -fsS')
        && str_contains($process->command, BACKUP_S3_DOWNLOAD_FILE)
        && str_contains($process->command, 'X-Amz-Signature=abc&x=1')
        && ! str_contains($process->command, 'docker exec'));
});

it('builds host file commands that the non-root sudo parser keeps whole', function () {
    $nonRoot = Server::factory()->make(['user' => 'deploy']);
    $upload = FileDownloadStorageService::buildHostFileUploadCommand("/data/it's.dmp", 'https://s3.example.com/put?a=1&b=2', ['Content-Disposition' => 'attachment']);
    $size = FileDownloadStorageService::buildHostFileSizeCommand("/data/it's.dmp");

    expect(parseCommandsByLineForSudo(collect([$upload, $size]), $nonRoot))->toBe(['sudo '.$upload, 'sudo '.$size])
        ->and(fn () => FileDownloadStorageService::buildHostFileSizeCommand('/data/a;rm -rf /'))->toThrow(Exception::class);
});
