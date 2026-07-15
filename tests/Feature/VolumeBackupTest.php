<?php

use App\Jobs\ScheduledJobManager;
use App\Jobs\VolumeBackupJob;
use App\Jobs\VolumeBackupRecoveryJob;
use App\Livewire\Project\Application\Backup\Create as CreateScheduledVolumeBackup;
use App\Livewire\Project\Shared\Storages\Show;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('provides the volume backup domain classes and relationship', function () {
    expect(class_exists(ScheduledVolumeBackup::class))->toBeTrue()
        ->and(class_exists(ScheduledVolumeBackupExecution::class))->toBeTrue()
        ->and(class_exists(VolumeBackupJob::class))->toBeTrue()
        ->and(class_exists(VolumeBackups::class))->toBeTrue()
        ->and(method_exists(LocalPersistentVolume::class, 'scheduledBackups'))->toBeTrue();
});

it('provides application backup index and detail routes', function () {
    expect(Route::has('project.application.backup.index'))->toBeTrue()
        ->and(Route::has('project.application.backup.show'))->toBeTrue()
        ->and(Route::has('download.volume-backup'))->toBeTrue();
});

it('creates a scheduled backup with a preselected volume from the shared modal', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    Livewire::test(CreateScheduledVolumeBackup::class, [
        'application' => $application,
        'selectedVolumeId' => $volume->id,
    ])
        ->assertSet('volumeId', $volume->id)
        ->assertSee($volume->name)
        ->set('frequency', 'daily')
        ->call('submit')
        ->assertDispatched('success');

    $backup = ScheduledVolumeBackup::query()->sole();

    expect($backup->local_persistent_volume_id)->toBe($volume->id)
        ->and($backup->frequency)->toBe('daily')
        ->and($backup->enabled)->toBeTrue();
});

it('shows volume backups on the application backups pages', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    $parameters = [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ];

    $this->get(route('project.application.backup.index', $parameters))
        ->assertOk()
        ->assertSee('Scheduled Backups')
        ->assertSee($volume->name);

    $this->get(route('project.application.backup.show', [...$parameters, 'backup_uuid' => $backup->uuid]))
        ->assertOk()
        ->assertSee('<h1>Volume Backups</h1>', false)
        ->assertSee($volume->name);
});

it('shows the configure backup modal trigger inside the volume card instead of inline backup settings', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    $component = Livewire::test(Show::class, [
        'storage' => $volume,
        'resource' => $application,
    ])
        ->set('isReadOnly', true)
        ->assertSee('Configure Backup')
        ->assertDontSee('Backups made while the application is writing');

    $html = $component->html();

    expect(strpos($html, 'Configure Backup'))
        ->toBeGreaterThan(strpos($html, '<form'))
        ->toBeLessThan(strpos($html, '</form>'));
});

it('only shows the backup enabled badge for an enabled volume backup', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => false,
    ]);

    $component = Livewire::test(Show::class, [
        'storage' => $volume,
        'resource' => $application,
    ])->assertDontSee('Backup enabled');

    $backup->update(['enabled' => true]);

    $component
        ->dispatch('refreshVolumeBackups')
        ->assertSeeInOrder(['Volume Name', 'Backup enabled']);

    Livewire::test(Show::class, [
        'storage' => $volume,
        'resource' => $application,
        'isFirst' => false,
    ])->assertSeeInOrder(['Volume Name', 'Backup enabled']);
});

it('stores volume backup schedules and executions', function () {
    expect(Schema::hasColumns('scheduled_volume_backups', [
        'uuid',
        'local_persistent_volume_id',
        'team_id',
        's3_storage_id',
        'frequency',
        'enabled',
        'save_s3',
        'disable_local_backup',
        'stop_during_backup',
        'retention_amount_locally',
        'retention_days_locally',
        'retention_max_storage_locally',
        'retention_amount_s3',
        'retention_days_s3',
        'retention_max_storage_s3',
        'timeout',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('scheduled_volume_backup_executions', [
            'uuid',
            'scheduled_volume_backup_id',
            'status',
            'message',
            'size',
            'filename',
            'stop_container_ids',
            'stop_recovery_pending',
            's3_cleanup_pending',
            'finished_at',
            'local_storage_deleted',
            's3_storage_deleted',
            's3_uploaded',
        ]))->toBeTrue();
});

it('exposes actions to manage and run volume backups', function () {
    expect(method_exists(VolumeBackups::class, 'save'))->toBeTrue()
        ->and(method_exists(VolumeBackups::class, 'backupNow'))->toBeTrue()
        ->and(method_exists(VolumeBackups::class, 'toggleEnabled'))->toBeTrue()
        ->and(method_exists(VolumeBackups::class, 'delete'))->toBeTrue()
        ->and(method_exists(VolumeBackups::class, 'cleanupFailed'))->toBeTrue()
        ->and(method_exists(VolumeBackups::class, 'cleanupDeleted'))->toBeTrue()
        ->and(method_exists(VolumeBackups::class, 'deleteBackup'))->toBeTrue();
});

it('renders volume backup executions like database backup executions', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume, $server] = createVolumeBackupApplication($team);
    $server->settings->update(['server_timezone' => 'Europe/Budapest']);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/archive.tar.gz',
        'size' => 1024,
        'finished_at' => now(),
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->assertSet('timezone', 'Europe/Budapest')
        ->assertSeeInOrder([
            'Scheduled Backup',
            'Save',
            'Disable Backup',
            'Backup Now',
            'Delete Backups and Schedule',
            'Persistent volume:',
            'Stop containers while creating the archive',
            'S3 Enabled',
            'Disable Local Backup',
            'S3 Storage',
            'Settings',
            'Frequency',
            'Timezone',
            'Timeout',
            'Backup Retention Settings',
            'Local Backup Retention',
            'Executions',
        ])
        ->assertSee('Persistent volume:')
        ->assertSee('app-data')
        ->assertSee('Scheduled Backup')
        ->assertSee('Save')
        ->assertSee('Disable Backup')
        ->assertSee('Backup Now')
        ->assertSee('Delete Backups and Schedule')
        ->assertSee('S3 Enabled')
        ->assertSee('Disable Local Backup')
        ->assertSee('S3 Storage')
        ->assertSee('(currently disabled)')
        ->assertSee('Timezone')
        ->assertSee('Setting a value to 0 means unlimited retention.')
        ->assertSee('Days to keep backups')
        ->assertSee('Maximum storage (GB)')
        ->assertSee('Executions')
        ->assertSee('Page 1 of 1')
        ->assertSee('Cleanup Failed Backups')
        ->assertSee('Cleanup Deleted')
        ->assertSee('Backup Availability:')
        ->assertSee('Local Storage')
        ->assertSee('Location: /data/coolify/backups/volumes/test/archive.tar.gz')
        ->assertSee('Download')
        ->assertSee('Delete')
        ->assertDontSee('border border-neutral-200', false);
});

it('cleans up failed and fully deleted volume backup execution records', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    $failed = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'failed',
    ]);
    $deleted = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'local_storage_deleted' => true,
        's3_storage_deleted' => true,
        's3_uploaded' => true,
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('cleanupFailed')
        ->assertDispatched('success')
        ->call('cleanupDeleted')
        ->assertDispatched('success');

    expect($failed->fresh())->toBeNull()
        ->and($deleted->fresh())->toBeNull();
});

it('deletes an individual volume backup archive and execution', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    Process::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/archive.tar.gz',
        'finished_at' => now(),
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('deleteBackup', $execution->id, 'password')
        ->assertDispatched('success');

    expect($execution->fresh())->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, 'archive.tar.gz'));
});

it('prevents another team from downloading a volume backup', function () {
    $backupTeam = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($backupTeam);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $backupTeam->id,
        'frequency' => 'daily',
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/archive.tar.gz',
    ]);
    $otherTeam = Team::factory()->create();
    signInForVolumeBackups($this, $otherTeam);

    $this->get(route('download.volume-backup', $execution->id))->assertForbidden();
});

it('enables and disables volume backups from the title action', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    $component = Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->assertSet('enabled', false)
        ->assertSee('Enable Backup')
        ->call('toggleEnabled')
        ->assertSet('enabled', true)
        ->assertSee('Disable Backup');

    expect(ScheduledVolumeBackup::query()->sole()->enabled)->toBeTrue();

    $component->call('toggleEnabled')->assertSet('enabled', false);

    expect(ScheduledVolumeBackup::query()->sole()->enabled)->toBeFalse();
});

function createVolumeBackupApplication(Team $team): array
{
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.10',
    ]);
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

    return [$application, $volume, $server];
}

function signInForVolumeBackups($testCase, Team $team): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $testCase->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

it('creates a local scheduled backup for a persistent volume', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->assertSee('Scheduled Backup')
        ->assertSee('Persistent volume:')
        ->assertSee('inconsistent or corrupted')
        ->assertSee('gracefully stop containers')
        ->set('frequency', 'daily')
        ->set('retentionAmountLocally', 5)
        ->set('retentionDaysLocally', 14)
        ->set('retentionMaxStorageLocally', 1.5)
        ->set('stopDuringBackup', true)
        ->call('save')
        ->assertDispatched('success');

    $backup = ScheduledVolumeBackup::query()->sole();

    expect($backup->local_persistent_volume_id)->toBe($volume->id)
        ->and($backup->team_id)->toBe($team->id)
        ->and($backup->frequency)->toBe('daily')
        ->and($backup->retention_amount_locally)->toBe(5)
        ->and($backup->retention_days_locally)->toBe(14)
        ->and($backup->retention_max_storage_locally)->toBe(1.5)
        ->and($backup->stop_during_backup)->toBeTrue()
        ->and($backup->save_s3)->toBeFalse();
});

it('only accepts a usable S3 storage owned by the current team', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $foreignStorage = S3Storage::create([
        'name' => 'Foreign S3',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => Team::factory()->create()->id,
        'is_usable' => true,
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->set('frequency', 'daily')
        ->set('saveToS3', true)
        ->set('s3StorageId', $foreignStorage->id)
        ->call('save')
        ->assertHasErrors('s3StorageId');

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('saves the database-style S3 backup controls immediately', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $s3Storage = S3Storage::create([
        'name' => 'Volume backups',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);

    $component = Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->assertSet('s3StorageId', $s3Storage->id)
        ->set('saveToS3', true)
        ->call('instantSave')
        ->set('disableLocalBackup', true)
        ->set('retentionAmountS3', 10)
        ->set('retentionDaysS3', 30)
        ->set('retentionMaxStorageS3', 5.5)
        ->call('instantSave');

    $backup = ScheduledVolumeBackup::query()->sole();
    expect($backup->save_s3)->toBeTrue()
        ->and($backup->s3_storage_id)->toBe($s3Storage->id)
        ->and($backup->disable_local_backup)->toBeTrue()
        ->and($backup->retention_amount_s3)->toBe(10)
        ->and($backup->retention_days_s3)->toBe(30)
        ->and($backup->retention_max_storage_s3)->toBe(5.5);

    $component->set('saveToS3', false)->call('instantSave');

    expect($backup->fresh()->save_s3)->toBeFalse()
        ->and($backup->fresh()->s3_storage_id)->toBeNull()
        ->and($backup->fresh()->disable_local_backup)->toBeFalse();
});

it('saves each editable volume backup checkbox immediately', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $s3Storage = S3Storage::create([
        'name' => 'Volume backups',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);

    $component = Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application]);
    $html = $component->html();

    foreach (['stopDuringBackup', 'saveToS3', 'disableLocalBackup'] as $property) {
        preg_match('/<input\b(?=[^>]*wire:model=(?:"'.$property.'"|'.$property.'))[^>]*>/', $html, $matches);

        expect($matches[0] ?? null)->not->toBeNull()
            ->and($matches[0])->toContain("wire:click='instantSave'");
    }

    $component->set('stopDuringBackup', true)->call('instantSave')->assertDispatched('success');
    expect($backup->refresh()->stop_during_backup)->toBeTrue();

    $component->set('stopDuringBackup', false)->call('instantSave')->assertDispatched('success');
    expect($backup->refresh()->stop_during_backup)->toBeFalse();
});

it('allows volume S3 backups to be disabled when no usable storage remains', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => null,
    ]);

    $component = Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->assertSet('saveToS3', true);

    preg_match('/<input\b(?=[^>]*wire:model=(?:"saveToS3"|saveToS3))[^>]*>/', $component->html(), $matches);
    expect($matches[0] ?? null)->not->toBeNull()
        ->and(preg_match('/\sdisabled(?:\s|\/>)/', $matches[0]))->toBe(0);

    $component->set('saveToS3', false)->call('instantSave')->assertDispatched('success');

    expect($backup->refresh()->save_s3)->toBeFalse()
        ->and($backup->s3_storage_id)->toBeNull();

    preg_match('/<input\b(?=[^>]*wire:model=(?:"saveToS3"|saveToS3))[^>]*>/', $component->html(), $matches);
    expect(preg_match('/\sdisabled(?:\s|\/>)/', $matches[0]))->toBe(1);
});

it('disables S3 volume backups when the storage is deleted', function () {
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $s3Storage = S3Storage::create([
        'name' => 'Volume backups',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/s3.tar.gz',
        's3_uploaded' => true,
        's3_cleanup_pending' => true,
    ]);

    $s3Storage->delete();

    expect($backup->fresh()->save_s3)->toBeFalse()
        ->and($backup->fresh()->s3_storage_id)->toBeNull()
        ->and($execution->fresh()->s3_storage_deleted)->toBeTrue()
        ->and($execution->fresh()->s3_cleanup_pending)->toBeFalse();
});

it('queues a manual backup before a schedule has been saved', function () {
    Queue::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('backupNow')
        ->assertDispatched('success');

    $backup = ScheduledVolumeBackup::query()->sole();

    expect($backup->enabled)->toBeFalse();
    Queue::assertPushed(VolumeBackupJob::class, fn (VolumeBackupJob $job) => $job->backup->is($backup));
});

it('deletes local archives before deleting a volume backup schedule', function () {
    Process::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/archive.tar.gz',
        'size' => 128,
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('delete', 'password')
        ->assertDispatched('success');

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, 'archive.tar.gz'));
});

it('refuses to delete a schedule while its backup is running', function () {
    Process::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'running',
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('delete', 'password')
        ->assertDispatched('error');

    expect($backup->fresh())->not->toBeNull();
    Process::assertNothingRan();
});

it('uses the backup operation lock while deleting a schedule', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    $lock = Cache::lock(VolumeBackupJob::lockKey($backup->id), 60);
    $lock->get();

    try {
        Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
            ->call('delete', 'password')
            ->assertDispatched('error');

        expect($backup->fresh())->not->toBeNull();
    } finally {
        $lock->release();
    }
});

it('prevents a volume with tracked backup archives from being deleted', function () {
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);

    expect(fn () => $volume->delete())->toThrow(QueryException::class);
    expect($volume->fresh())->not->toBeNull();
});

it('marks a running execution failed even when the job instance lost its execution state', function () {
    Process::fake();
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'running',
        'filename' => '/data/coolify/backups/volumes/test/timed-out.tar.gz',
    ]);

    (new VolumeBackupJob($backup))->failed(new RuntimeException('Worker timed out'));

    expect($execution->fresh()->status)->toBe('failed')
        ->and($execution->fresh()->message)->toBe('Worker timed out')
        ->and($execution->fresh()->finished_at)->not->toBeNull()
        ->and($execution->fresh()->filename)->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, 'timed-out.tar.gz'));
});

it('archives a named volume on its server', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => true,
        'retention_amount_locally' => 7,
        'retention_amount_s3' => 7,
        'timeout' => 3600,
    ]);

    Process::fake([
        '*du -b*' => '128',
        '*' => '',
    ]);

    $job = new VolumeBackupJob($backup);
    $middleware = $job->middleware();
    $job->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($job->queue)->toBe(crons_queue())
        ->and($execution->status)->toBe('success')
        ->and($execution->size)->toBe(128)
        ->and($execution->filename)->toEndWith('.tar.gz');

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker volume inspect')
        && str_contains($process->command, 'docker run --rm --name ')
        && str_contains($process->command, 'app-data:/volume:ro')
        && str_contains($process->command, 'tar -czf -')
        && str_contains($process->command, '> ')
        && str_contains($process->command, '.tar.gz')
        && ! str_contains($process->command, ':/backup'));
});

it('removes local volume backups older than the configured retention days', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'retention_amount_locally' => 0,
        'retention_days_locally' => 7,
        'retention_max_storage_locally' => 0,
    ]);
    $expiredExecution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/expired.tar.gz',
        'size' => 64,
    ]);
    ScheduledVolumeBackupExecution::query()->whereKey($expiredExecution)->update(['created_at' => now()->subDays(8)]);
    $recentExecution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/recent.tar.gz',
        'size' => 64,
    ]);
    ScheduledVolumeBackupExecution::query()->whereKey($recentExecution)->update(['created_at' => now()->subDay()]);

    Process::fake([
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    expect($expiredExecution->fresh())->toBeNull()
        ->and($recentExecution->fresh())->not->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, 'expired.tar.gz'));
});

it('removes oldest local volume backups over the configured storage limit', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'retention_amount_locally' => 0,
        'retention_days_locally' => 0,
        'retention_max_storage_locally' => 0.00000015,
    ]);
    $oldExecution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/over-limit.tar.gz',
        'size' => 64,
    ]);
    ScheduledVolumeBackupExecution::query()->whereKey($oldExecution)->update(['created_at' => now()->subDay()]);

    Process::fake([
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    expect($oldExecution->fresh())->toBeNull()
        ->and($backup->executions()->where('status', 'success')->count())->toBe(1);
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, 'over-limit.tar.gz'));
});

it('keeps a successful backup successful when retention cleanup fails', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'disable_local_backup' => true,
        'retention_amount_locally' => 1,
    ]);
    $oldExecution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/old.tar.gz',
        'size' => 64,
        'created_at' => now()->subDay(),
    ]);

    Process::fake([
        '*rm -f*' => Process::result(errorOutput: 'permission denied', exitCode: 1),
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    $latestExecution = $backup->executions()->first();

    expect($latestExecution->status)->toBe('success')
        ->and($latestExecution->filename)->not->toBeNull()
        ->and($oldExecution->fresh()->local_storage_deleted)->toBeFalse();
});

it('stops and restarts containers that use the volume when requested', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'stop_during_backup' => true,
    ]);

    Process::fake([
        '*docker ps -q*' => "abc123\ndef456",
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, 'trap cleanup EXIT')
        && str_contains($process->command, 'exit 143')
        && str_contains($process->command, 'TERM')
        && str_contains($process->command, 'docker stop')
        && str_contains($process->command, 'docker start')
        && str_contains($process->command, 'state_file='));
    Process::assertRan(fn ($process) => str_contains($process->command, '{{println .Source}}{{println .Name}}')
        && str_contains($process->command, "grep -Fqx -- 'app-data'")
        && str_contains($process->command, '{{.State.Paused}}')
        && str_contains($process->command, 'continue'));
});

it('finds containers using a bind mounted host path before stopping', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $volume->update(['host_path' => '/srv/app-data']);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'stop_during_backup' => true,
    ]);

    Process::fake([
        '*docker ps -q*' => 'abc123',
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, '{{println .Source}}{{println .Name}}')
        && str_contains($process->command, "grep -Fqx -- '/srv/app-data'"));
});

it('retries container recovery when a timed out backup left a container stopped', function () {
    Queue::fake();
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'running',
        'stop_recovery_pending' => true,
    ]);
    Process::fake([
        '*cat *coolify-volume-backup-*' => 'abc123',
        '*docker start*' => Process::result(errorOutput: 'daemon unavailable', exitCode: 1),
    ]);

    (new VolumeBackupJob($backup))->failed(new RuntimeException('Worker timed out'));

    expect($execution->fresh()->status)->toBe('failed')
        ->and($execution->fresh()->message)->toContain('Container recovery failed')
        ->and($execution->fresh()->stop_container_ids)->toBe(['abc123'])
        ->and($execution->fresh()->stop_recovery_pending)->toBeTrue();
    Queue::assertPushed(VolumeBackupRecoveryJob::class);
});

it('restarts containers and removes a partial archive when archiving fails', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'stop_during_backup' => true,
    ]);
    Process::fake([
        '*docker ps -q*' => 'abc123',
        '*trap cleanup EXIT*' => Process::result(errorOutput: 'archive failed', exitCode: 1),
        '*' => '',
    ]);

    expect(fn () => (new VolumeBackupJob($backup))->handle())->toThrow(RuntimeException::class);

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    expect($execution->status)->toBe('failed')
        ->and($execution->stop_container_ids)->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, '.tar.gz'));
});

it('throws when the S3 filesystem reports that deletion failed', function () {
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->andReturnFalse();
    Storage::shouldReceive('build')->once()->andReturn($disk);
    $s3 = new S3Storage([
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'us-east-1',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
    ]);

    expect(fn () => deleteBackupsS3('archive.tar.gz', $s3))
        ->toThrow(RuntimeException::class, 'could not be deleted');
});

it('cleans an interrupted S3 upload and coordinates recovery with the backup lock', function () {
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $s3Storage = S3Storage::create([
        'name' => 'Volume backups',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'failed',
        'filename' => '/data/coolify/backups/volumes/test/interrupted.tar.gz',
        's3_cleanup_pending' => true,
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('build')->once()->andReturn($disk);
    $job = new VolumeBackupRecoveryJob($execution);

    expect($job->middleware()[0]->getLockKey($job))->toBe(VolumeBackupJob::lockKey($backup->id));
    $job->handle();

    expect($execution->fresh()->s3_cleanup_pending)->toBeFalse()
        ->and($execution->fresh()->s3_storage_deleted)->toBeTrue();
});

it('keeps the S3 key tracked when interrupted upload cleanup must be retried', function () {
    Queue::fake();
    Process::fake();
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $s3Storage = S3Storage::create([
        'name' => 'Volume backups',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'running',
        'filename' => '/data/coolify/backups/volumes/test/interrupted.tar.gz',
        's3_cleanup_pending' => true,
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->andReturnFalse();
    Storage::shouldReceive('build')->once()->andReturn($disk);

    (new VolumeBackupJob($backup))->failed(new RuntimeException('Worker timed out'));

    expect($execution->fresh()->filename)->toBe('/data/coolify/backups/volumes/test/interrupted.tar.gz')
        ->and($execution->fresh()->s3_cleanup_pending)->toBeTrue();
    Queue::assertPushed(VolumeBackupRecoveryJob::class);
});

it('dispatches due scheduled volume backups', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow('2026-07-15 12:00:00');
    Queue::fake();
    $team = Team::factory()->create();
    [$application, $volume, $server] = createVolumeBackupApplication($team);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    expect($server->fresh()->isFunctional())->toBeTrue();
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    Cache::forget("scheduled-volume-backup:{$backup->id}");
    expect($backup->server()?->isFunctional())->toBeTrue();

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(
        VolumeBackupJob::class,
        fn (VolumeBackupJob $job) => $job->backup->is($backup),
    );
});

it('dispatches pending recovery without starting another volume backup', function () {
    config(['constants.coolify.self_hosted' => true]);
    Queue::fake();
    $team = Team::factory()->create();
    [$application, $volume, $server] = createVolumeBackupApplication($team);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $backup = ScheduledVolumeBackup::create([
        'local_persistent_volume_id' => $volume->id,
        'team_id' => $team->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'failed',
        'stop_recovery_pending' => true,
    ]);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(
        VolumeBackupRecoveryJob::class,
        fn (VolumeBackupRecoveryJob $job) => $job->execution->is($execution),
    );
    Queue::assertNotPushed(VolumeBackupJob::class);
});
