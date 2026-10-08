<?php

use App\Livewire\Project\Database\BackupEdit;
use App\Livewire\Project\Database\BackupExecutions;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Livewire\Storage\Resources as StorageResources;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createS3StorageForDestinationsFormTest(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => str($name)->slug()->toString(),
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}

/**
 * @return array{0: Application, 1: LocalPersistentVolume, 2: StandalonePostgresql}
 */
function createResourcesForDestinationsFormTest(Team $team): array
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
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
    $database = StandalonePostgresql::create([
        'name' => 'pg-destinations',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    return [$application, $volume, $database];
}

function createDatabaseBackupForDestinationsFormTest(StandalonePostgresql $database, array $overrides = []): ScheduledDatabaseBackup
{
    return ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $database->team()->id,
        ...$overrides,
    ]);
}

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->user->teams()->attach($this->otherTeam, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    [$this->application, $this->volume, $this->database] = createResourcesForDestinationsFormTest($this->team);
    $this->firstStorage = createS3StorageForDestinationsFormTest($this->team, 'First S3');
    $this->secondStorage = createS3StorageForDestinationsFormTest($this->team, 'Second S3');
    $this->foreignStorage = createS3StorageForDestinationsFormTest($this->otherTeam, 'Foreign S3');
});

it('saves two S3 destinations for a database backup schedule', function () {
    $backup = createDatabaseBackupForDestinationsFormTest($this->database);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s, 'section' => 's3'])
        ->assertSee('First S3')
        ->assertSee('Second S3')
        ->assertSet('s3StorageIds', [$this->firstStorage->id])
        ->set('saveS3', true)
        ->set('s3StorageIds', [(string) $this->firstStorage->id, (string) $this->secondStorage->id])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeTruthy()
        ->and($backup->s3_storage_id)->toBe($this->firstStorage->id)
        ->and($backup->s3Storages()->pluck('s3_storages.id')->sort()->values()->all())
        ->toBe([$this->firstStorage->id, $this->secondStorage->id]);
});

it('saves two S3 destinations for a volume backup schedule', function () {
    Livewire::test(VolumeBackups::class, ['storage' => $this->volume, 'resource' => $this->application])
        ->set('frequency', 'daily')
        ->set('saveToS3', true)
        ->set('s3StorageIds', [(string) $this->secondStorage->id, (string) $this->firstStorage->id])
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $backup = ScheduledVolumeBackup::query()->sole();
    expect($backup->save_s3)->toBeTrue()
        ->and($backup->s3_storage_id)->toBe($this->secondStorage->id)
        ->and($backup->s3Storages()->pluck('s3_storages.id')->sort()->values()->all())
        ->toBe([$this->firstStorage->id, $this->secondStorage->id]);
});

it('drops S3 storages of another team after a team switch', function () {
    $backup = createDatabaseBackupForDestinationsFormTest($this->database, [
        'save_s3' => true,
        's3_storage_id' => $this->firstStorage->id,
    ]);
    $backup->syncS3Storages([$this->firstStorage->id]);

    $databaseComponent = Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s]);
    $volumeComponent = Livewire::test(VolumeBackups::class, ['storage' => $this->volume, 'resource' => $this->application]);
    session(['currentTeam' => $this->otherTeam]);

    $databaseComponent->set('s3StorageIds', [$this->foreignStorage->id])
        ->assertHasErrors('s3StorageIds');
    expect($backup->fresh()->s3Storages()->pluck('s3_storages.id')->all())->toBe([$this->firstStorage->id]);

    $databaseComponent->set('s3StorageIds', [$this->secondStorage->id, $this->foreignStorage->id])
        ->assertHasNoErrors();
    expect($backup->fresh()->s3_storage_id)->toBe($this->secondStorage->id)
        ->and($backup->fresh()->s3Storages()->pluck('s3_storages.id')->all())->toBe([$this->secondStorage->id]);

    $volumeComponent->set('frequency', 'daily')
        ->set('saveToS3', true)
        ->set('s3StorageIds', [$this->foreignStorage->id])
        ->assertHasErrors('s3StorageIds');
    expect(ScheduledVolumeBackup::query()->count())->toBe(0);

    $volumeComponent->set('s3StorageIds', [$this->firstStorage->id, $this->foreignStorage->id])
        ->assertHasNoErrors();
    expect(ScheduledVolumeBackup::query()->sole()->s3Storages()->pluck('s3_storages.id')->all())
        ->toBe([$this->firstStorage->id]);
});

it('rejects S3 backups without a destination', function () {
    $backup = createDatabaseBackupForDestinationsFormTest($this->database, [
        'save_s3' => true,
        's3_storage_id' => $this->firstStorage->id,
    ]);
    $backup->syncS3Storages([$this->firstStorage->id]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->set('s3StorageIds', [])
        ->call('submit')
        ->assertHasErrors('s3StorageIds')
        ->assertDispatched('error');

    expect($backup->fresh()->save_s3)->toBeTruthy()
        ->and($backup->fresh()->s3Storages()->pluck('s3_storages.id')->all())->toBe([$this->firstStorage->id]);

    Livewire::test(VolumeBackups::class, ['storage' => $this->volume, 'resource' => $this->application])
        ->set('frequency', 'daily')
        ->set('saveToS3', true)
        ->set('s3StorageIds', [])
        ->call('save')
        ->assertHasErrors('s3StorageIds');

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('moves and removes one destination of a multi-destination schedule from the storage page', function () {
    $thirdStorage = createS3StorageForDestinationsFormTest($this->team, 'Third S3');
    $databaseBackup = createDatabaseBackupForDestinationsFormTest($this->database, ['save_s3' => true]);
    $databaseBackup->syncS3Storages([$this->firstStorage->id, $this->secondStorage->id]);
    $volumeBackup = ScheduledVolumeBackup::create([
        'backupable_type' => $this->volume->getMorphClass(),
        'backupable_id' => $this->volume->id,
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'enabled' => true,
        'save_s3' => true,
    ]);
    $volumeBackup->syncS3Storages([$this->firstStorage->id, $this->secondStorage->id]);

    Livewire::test(StorageResources::class, ['storage' => $this->secondStorage])
        ->assertSet("selectedStorages.{$databaseBackup->id}", $this->secondStorage->id)
        ->assertSet("selectedVolumeStorages.{$volumeBackup->id}", $this->secondStorage->id)
        ->assertSee('pg-destinations')
        ->assertSee('app-data');

    Livewire::test(StorageResources::class, ['storage' => $this->firstStorage])
        ->set("selectedStorages.{$databaseBackup->id}", $thirdStorage->id)
        ->call('moveBackup', $databaseBackup->id)
        ->set("selectedVolumeStorages.{$volumeBackup->id}", $thirdStorage->id)
        ->call('moveVolumeBackup', $volumeBackup->id);

    foreach ([$databaseBackup, $volumeBackup] as $backup) {
        $backup->refresh();
        expect($backup->s3_storage_id)->toBe($thirdStorage->id)
            ->and($backup->s3Storages()->pluck('s3_storages.id')->sort()->values()->all())
            ->toBe([$this->secondStorage->id, $thirdStorage->id]);
    }

    Livewire::test(StorageResources::class, ['storage' => $this->secondStorage])
        ->call('disableS3', $databaseBackup->id)
        ->call('disableVolumeS3', $volumeBackup->id);

    foreach ([$databaseBackup, $volumeBackup] as $backup) {
        $backup->refresh();
        expect($backup->save_s3)->toBeTruthy()
            ->and($backup->s3_storage_id)->toBe($thirdStorage->id)
            ->and($backup->s3Storages()->pluck('s3_storages.id')->all())->toBe([$thirdStorage->id]);
    }

    Livewire::test(StorageResources::class, ['storage' => $thirdStorage])
        ->call('disableS3', $databaseBackup->id)
        ->call('disableVolumeS3', $volumeBackup->id);

    foreach ([$databaseBackup, $volumeBackup] as $backup) {
        $backup->refresh();
        expect($backup->save_s3)->toBeFalsy()
            ->and($backup->s3_storage_id)->toBeNull()
            ->and($backup->s3Storages()->count())->toBe(0);
    }
});

it('shows the copy status of each destination in the execution lists', function () {
    $databaseBackup = createDatabaseBackupForDestinationsFormTest($this->database, ['save_s3' => true]);
    $databaseBackup->syncS3Storages([$this->firstStorage->id, $this->secondStorage->id]);
    $databaseExecution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $databaseBackup->id,
        'status' => 'success',
        'filename' => '/backups/dump.dmp',
        's3_uploaded' => false,
    ]);
    $volumeBackup = ScheduledVolumeBackup::create([
        'backupable_type' => $this->volume->getMorphClass(),
        'backupable_id' => $this->volume->id,
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $volumeBackup->syncS3Storages([$this->firstStorage->id, $this->secondStorage->id]);
    $volumeExecution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $volumeBackup->id,
        'status' => 'success',
        'filename' => '/backups/volume.tar.gz',
        's3_uploaded' => false,
    ]);
    foreach ([$databaseExecution, $volumeExecution] as $execution) {
        $execution->s3Replicas()->create(['s3_storage_id' => $this->firstStorage->id, 's3_uploaded' => true]);
        $execution->s3Replicas()->create(['s3_storage_id' => $this->secondStorage->id, 's3_uploaded' => false, 'message' => 'Access denied']);
    }

    Livewire::test(BackupExecutions::class, ['backup' => $databaseBackup])
        ->assertSeeInOrder(['First S3 Available', 'Second S3 Failed'])
        ->assertSee('Access denied')
        ->assertSee('Delete the selected backup permanently from every S3 storage');

    Livewire::test(VolumeBackups::class, ['storage' => $this->volume, 'resource' => $this->application, 'section' => 'executions'])
        ->assertSeeInOrder(['First S3', 'Second S3 failed'])
        ->assertSee('Delete the selected backup permanently from every S3 storage');
});

it('keeps a selected destination that is unusable for a while when other settings are saved', function () {
    $databaseBackup = createDatabaseBackupForDestinationsFormTest($this->database, ['save_s3' => true]);
    $databaseBackup->syncS3Storages([$this->firstStorage->id, $this->secondStorage->id]);
    $volumeBackup = ScheduledVolumeBackup::create([
        'backupable_type' => $this->volume->getMorphClass(),
        'backupable_id' => $this->volume->id,
        'team_id' => $this->team->id,
        'frequency' => '0 0 * * *',
        'save_s3' => true,
    ]);
    $volumeBackup->syncS3Storages([$this->firstStorage->id, $this->secondStorage->id]);
    $this->secondStorage->update(['is_usable' => false]);

    Livewire::test(BackupEdit::class, ['backup' => $databaseBackup->fresh(), 'availableS3Storages' => $this->team->s3s, 'section' => 's3'])
        ->assertSee('Second S3 (unavailable)')
        ->set('timeout', 7200)
        ->call('submit')
        ->assertHasNoErrors();
    Livewire::test(VolumeBackups::class, ['storage' => $this->volume, 'resource' => $this->application, 'section' => 's3'])
        ->assertSee('Second S3 (unavailable)')
        ->set('stopDuringBackup', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($databaseBackup->s3Storages()->pluck('s3_storages.id')->sort()->values()->all())
        ->toBe([$this->firstStorage->id, $this->secondStorage->id])
        ->and($volumeBackup->s3Storages()->pluck('s3_storages.id')->sort()->values()->all())
        ->toBe([$this->firstStorage->id, $this->secondStorage->id]);
});
