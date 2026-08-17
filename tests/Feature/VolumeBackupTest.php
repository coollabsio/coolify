<?php

use App\Jobs\DeleteResourceJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\VolumeBackupJob;
use App\Jobs\VolumeBackupRecoveryJob;
use App\Livewire\Project\Application\Backup\Create as CreateScheduledVolumeBackup;
use App\Livewire\Project\Service\FileStorage;
use App\Livewire\Project\Shared\Storages\Show;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('provides the volume backup domain classes and relationship', function () {
    expect(class_exists(ScheduledVolumeBackup::class))->toBeTrue()
        ->and(class_exists(ScheduledVolumeBackupExecution::class))->toBeTrue()
        ->and(class_exists(VolumeBackupJob::class))->toBeTrue()
        ->and(class_exists(VolumeBackups::class))->toBeTrue()
        ->and(method_exists(LocalPersistentVolume::class, 'scheduledBackups'))->toBeTrue()
        ->and(method_exists(LocalFileVolume::class, 'scheduledBackups'))->toBeTrue();
});

it('includes parallel gzip support in the Coolify helper image', function () {
    $dockerfile = file_get_contents(base_path('docker/coolify-helper/Dockerfile'));

    expect($dockerfile)->toContain('pigz');
});

it('keeps the volume backup script inside the Livewire root element', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/storages/volume-backups.blade.php'));

    expect(strrpos($view, '@endscript'))->toBeLessThan(strrpos($view, '</div>'));
});

it('shows the S3 configuration state in application and service backup tables', function () {
    $views = [
        resource_path('views/livewire/project/application/backup/index.blade.php'),
        resource_path('views/livewire/project/service/volume-backup/index.blade.php'),
    ];

    foreach ($views as $view) {
        expect(file_get_contents($view))
            ->toContain('<span>S3</span>')
            ->toContain("'Configured'")
            ->toContain("'Unavailable'")
            ->toContain("'Not set'");
    }
});

it('targets named volumes and application directory mounts through one backup relation', function () {
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $directory = LocalFileVolume::unguarded(fn () => LocalFileVolume::withoutEvents(fn () => LocalFileVolume::create([
        'uuid' => new_public_id(),
        'fs_path' => './uploads',
        'mount_path' => '/app/uploads',
        'is_directory' => true,
        'is_based_on_git' => false,
        'is_preview_suffix_enabled' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ])));

    $volumeBackup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    $directoryBackup = $directory->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'weekly',
    ]);

    expect($volumeBackup->backupable->is($volume))->toBeTrue()
        ->and($volumeBackup->targetType())->toBe('Volume')
        ->and($volumeBackup->targetName())->toBe('app-data')
        ->and($volumeBackup->sourcePath())->toBe('app-data')
        ->and($directoryBackup->backupable->is($directory))->toBeTrue()
        ->and($directoryBackup->targetType())->toBe('Directory')
        ->and($directoryBackup->targetName())->toBe('./uploads')
        ->and($directoryBackup->sourcePath())->toBe($application->workdir().'/uploads')
        ->and($directoryBackup->server()?->id)->toBe($application->destination->server->id);
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

    $component = Livewire::test(CreateScheduledVolumeBackup::class, [
        'application' => $application,
        'selectedTargetKey' => 'volume:'.$volume->id,
    ])
        ->assertSet('targetKey', 'volume:'.$volume->id)
        ->assertSee($volume->name)
        ->assertDontSee('Save to S3')
        ->set('frequency', 'daily')
        ->call('submit')
        ->assertDispatched('success');

    $backup = ScheduledVolumeBackup::query()->sole();

    $component->assertRedirectToRoute('project.application.backup.show', [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    expect($backup->backupable->is($volume))->toBeTrue()
        ->and($backup->frequency)->toBe('daily')
        ->and($backup->enabled)->toBeTrue()
        ->and($backup->save_s3)->toBeFalse()
        ->and($backup->s3_storage_id)->toBeNull();
});

it('handles scheduled backup persistence failures', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $shouldFail = true;

    ScheduledVolumeBackup::creating(function () use (&$shouldFail): void {
        if ($shouldFail) {
            $shouldFail = false;

            throw new RuntimeException('Scheduled backup persistence failed.');
        }
    });

    Livewire::test(CreateScheduledVolumeBackup::class, [
        'application' => $application,
        'selectedTargetKey' => 'volume:'.$volume->id,
    ])
        ->set('frequency', 'daily')
        ->call('submit')
        ->assertDispatched('error', 'Scheduled backup persistence failed.');

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('respects the instance wire navigate setting after creating a scheduled backup', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create([
        'id' => 0,
        'is_wire_navigate_enabled' => false,
    ]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    $component = Livewire::test(CreateScheduledVolumeBackup::class, [
        'application' => $application,
        'selectedTargetKey' => 'volume:'.$volume->id,
    ])
        ->set('frequency', 'daily')
        ->call('submit');

    $backup = ScheduledVolumeBackup::query()->sole();

    $component->assertRedirectToRoute('project.application.backup.show', [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    expect($component->effects)->not->toHaveKey('redirectUsingNavigate');
});

it('creates a scheduled backup for a preselected application directory', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application] = createVolumeBackupApplication($team);
    $directory = createApplicationBackupDirectory($application);

    Livewire::test(CreateScheduledVolumeBackup::class, [
        'application' => $application,
        'selectedTargetKey' => 'directory:'.$directory->id,
    ])
        ->assertSet('targetKey', 'directory:'.$directory->id)
        ->assertSee('Directory: '.$directory->fs_path)
        ->set('frequency', 'daily')
        ->call('submit')
        ->assertDispatched('success');

    expect(ScheduledVolumeBackup::query()->sole()->backupable->is($directory))->toBeTrue();
});

it('rejects files and directory mounts owned by another application as backup targets', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application] = createVolumeBackupApplication($team);
    $otherApplication = Application::factory()->create([
        'environment_id' => $application->environment_id,
        'destination_id' => $application->destination_id,
        'destination_type' => $application->destination_type,
    ]);
    $foreignDirectory = createApplicationBackupDirectory($otherApplication);
    $file = createApplicationBackupDirectory($application, './config.json');
    $file->update(['is_directory' => false]);

    Livewire::test(CreateScheduledVolumeBackup::class, ['application' => $application])
        ->set('targetKey', 'directory:'.$file->id)
        ->call('submit')
        ->assertHasErrors('targetKey')
        ->set('targetKey', 'directory:'.$foreignDirectory->id)
        ->call('submit')
        ->assertHasErrors('targetKey');

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('shows volume backups on the application backups pages', function () {
    config(['cache.default' => 'array', 'app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
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
        ->assertSee('<h1>Backups</h1>', false)
        ->assertSee($volume->name);
});

it('shows directory backups on the application backup index and detail pages', function () {
    config(['cache.default' => 'array', 'app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application] = createVolumeBackupApplication($team);
    $directory = createApplicationBackupDirectory($application);
    $backup = $directory->scheduledBackups()->create([
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
        ->assertSee('Directory: '.$directory->fs_path);

    $this->get(route('project.application.backup.show', [...$parameters, 'backup_uuid' => $backup->uuid]))
        ->assertOk()
        ->assertSee('<h1>Backups</h1>', false)
        ->assertSee($directory->fs_path);
});

it('splits scheduled backup settings and executions across dedicated urls', function () {
    config(['cache.default' => 'array', 'app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/archive.tar.gz',
        'finished_at' => now(),
    ]);
    $parameters = [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'backup_uuid' => $backup->uuid,
    ];
    $generalUrl = route('project.application.backup.show', $parameters);

    $this->get($generalUrl)
        ->assertOk()
        ->assertSee('General')
        ->assertSee('S3')
        ->assertSee('Retention')
        ->assertSee('Executions')
        ->assertSee('Danger Zone')
        ->assertSee('Stop containers while creating the archive')
        ->assertDontSee('S3 Enabled')
        ->assertDontSee('Number of backups to keep')
        ->assertDontSee('Backup Availability:')
        ->assertDontSee('Delete Backups and Schedule');

    $this->get($generalUrl.'/s3')
        ->assertOk()
        ->assertSeeText('No validated S3 storage')
        ->assertDontSee('Disable Local Backup')
        ->assertDontSee('Enable S3')
        ->assertDontSee('Disable S3')
        ->assertDontSee('S3 Storage Retention')
        ->assertDontSee('Local Backup Retention')
        ->assertDontSee('Frequency')
        ->assertDontSee('Backup Availability:');

    $s3View = file_get_contents(resource_path('views/livewire/project/shared/storages/volume-backups/s3.blade.php'));
    expect(strpos($s3View, '<span>S3 Storage</span>'))
        ->toBeLessThan(strpos($s3View, 'label="Disable Local Backup"'));

    $this->get($generalUrl.'/retention')
        ->assertOk()
        ->assertSee('Local Backup Retention')
        ->assertSee('S3 Storage Retention')
        ->assertSee('Number of backups to keep')
        ->assertDontSee('Stop containers while creating the archive')
        ->assertDontSee('Backup Availability:');

    $this->get($generalUrl.'/executions')
        ->assertOk()
        ->assertSee('Backup Availability:')
        ->assertDontSee('Stop containers while creating the archive')
        ->assertDontSee('Number of backups to keep');

    $this->get($generalUrl.'/danger')
        ->assertOk()
        ->assertSee('Danger Zone')
        ->assertSee('Delete Scheduled Backup')
        ->assertSee('Delete Backups and Schedule')
        ->assertDontSee('Stop containers while creating the archive')
        ->assertDontSee('Number of backups to keep')
        ->assertDontSee('Backup Availability:');
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
        ->assertSee('Backup')
        ->assertDontSee('Backups made while the application is writing');

    $html = $component->html();

    // Read-only volume rows are table cells (no form); backup action still renders in the row.
    expect($html)
        ->toContain('Configure Volume Backup')
        ->toContain('data-table-row')
        ->toContain('Backup');
});

it('only shows the backup enabled badge for an enabled volume backup', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => false,
    ]);

    $component = Livewire::test(Show::class, [
        'storage' => $volume,
        'resource' => $application,
    ])->assertDontSee('table-badge-success', false);

    $backup->update(['enabled' => true]);

    $backupUrl = route('project.application.backup.show', [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    $component
        ->dispatch('refreshVolumeBackups')
        ->assertSee('table-badge-success', false)
        ->assertSee('Volume backup is enabled')
        ->assertSee('href="'.$backupUrl.'"', false);

    Livewire::test(Show::class, [
        'storage' => $volume,
        'resource' => $application,
        'isFirst' => false,
    ])
        ->assertSee('table-badge-success', false)
        ->assertSee('Volume backup is enabled');
});

it('links the backup enabled badge to a filtered backup list when the application has multiple schedules', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => true,
    ]);
    createApplicationBackupDirectory($application)->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'weekly',
        'enabled' => true,
    ]);

    $backupUrl = route('project.application.backup.index', [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'search' => $volume->name,
    ]);

    Livewire::test(Show::class, [
        'storage' => $volume,
        'resource' => $application,
    ])
        ->assertSee('table-badge-success', false)
        ->assertSee('Volume backup is enabled')
        ->assertSee('href="'.$backupUrl.'"', false);
});

it('offers backup configuration and status on application directory mounts', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application] = createVolumeBackupApplication($team);
    $directory = createApplicationBackupDirectory($application);

    $component = Livewire::test(FileStorage::class, ['fileStorage' => $directory])
        ->assertSee('Configure Backup')
        ->assertSeeInOrder(['Convert to file', 'Configure Backup', 'Delete'])
        ->assertDontSee('Backup enabled');

    $backup = $directory->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => true,
    ]);

    $backupUrl = route('project.application.backup.show', [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    $component
        ->dispatch('refreshVolumeBackups')
        ->assertSee('Backup enabled')
        ->assertSee('href="'.$backupUrl.'"', false);
});

it('links the backup enabled badge to service database backups for database directory mounts', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application] = createVolumeBackupApplication($team);
    $service = Service::factory()->create([
        'environment_id' => $application->environment_id,
        'destination_id' => $application->destination_id,
        'destination_type' => $application->destination_type,
    ]);
    $database = ServiceDatabase::create([
        'uuid' => new_public_id(),
        'name' => 'postgres',
        'image' => 'postgres:17-alpine',
        'service_id' => $service->id,
    ]);
    $directory = LocalFileVolume::unguarded(fn () => LocalFileVolume::withoutEvents(fn () => LocalFileVolume::create([
        'uuid' => new_public_id(),
        'fs_path' => './postgres-data',
        'mount_path' => '/var/lib/postgresql/data',
        'is_directory' => true,
        'is_based_on_git' => false,
        'is_preview_suffix_enabled' => true,
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ])));
    $directory->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => true,
    ]);

    $backupUrl = route('project.service.database.backups', [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'service_uuid' => $service->uuid,
        'stack_service_uuid' => $database->uuid,
    ]);

    Livewire::test(FileStorage::class, ['fileStorage' => $directory])
        ->assertSee('Backup enabled')
        ->assertSee('href="'.$backupUrl.'"', false);
});

it('prevents a backed up directory from being converted or deleted', function () {
    Process::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application] = createVolumeBackupApplication($team);
    $directory = createApplicationBackupDirectory($application);
    $directory->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);

    Livewire::test(FileStorage::class, ['fileStorage' => $directory])
        ->call('convertToFile')
        ->assertDispatched('error')
        ->call('delete', 'password')
        ->assertDispatched('error');

    expect($directory->fresh())->not->toBeNull()
        ->and($directory->fresh()->is_directory)->toBeTrue();
});

it('stores volume backup schedules and executions', function () {
    expect(Schema::hasColumns('scheduled_volume_backups', [
        'uuid',
        'backupable_type',
        'backupable_id',
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

it('records the S3 storage used by each volume backup execution', function () {
    expect(Schema::hasColumn('scheduled_volume_backup_executions', 's3_storage_id'))->toBeTrue()
        ->and((new ScheduledVolumeBackupExecution)->s3())->not->toBeNull();
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

it('declares the volume backup action return types', function () {
    $backupNowReturnType = (new ReflectionMethod(VolumeBackups::class, 'backupNow'))->getReturnType();
    $deleteReturnType = (new ReflectionMethod(VolumeBackups::class, 'delete'))->getReturnType();

    expect($backupNowReturnType)->toBeInstanceOf(ReflectionUnionType::class)
        ->and(collect($backupNowReturnType->getTypes())->map->getName()->all())->toEqualCanonicalizing([
            RedirectResponse::class,
            Redirector::class,
            'null',
        ])
        ->and($deleteReturnType)->toBeInstanceOf(ReflectionUnionType::class)
        ->and(collect($deleteReturnType->getTypes())->map->getName()->all())->toEqualCanonicalizing(['bool', 'string']);
});

it('renders volume backup executions like database backup executions', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume, $server] = createVolumeBackupApplication($team);
    $server->settings->update(['server_timezone' => 'Europe/Budapest']);
    $backup = $volume->scheduledBackups()->create([
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

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application, 'section' => 'executions'])
        ->assertSet('timezone', 'Europe/Budapest')
        ->assertSeeInOrder([
            'Executions',
            'Success',
            'Volume: app-data',
            'Backup Availability:',
        ])
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
    $backup = $volume->scheduledBackups()->create([
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
    $backup = $volume->scheduledBackups()->create([
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

it('deletes an individual S3 archive from the storage recorded on its execution', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $originalStorage = S3Storage::create([
        'name' => 'Original storage',
        'region' => 'us-east-1',
        'key' => 'original-key',
        'secret' => 'secret',
        'bucket' => 'original-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $newStorage = S3Storage::create([
        'name' => 'New storage',
        'region' => 'us-east-1',
        'key' => 'new-key',
        'secret' => 'secret',
        'bucket' => 'new-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $newStorage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $originalStorage->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/historical.tar.gz',
        'local_storage_deleted' => true,
        's3_uploaded' => true,
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')
        ->once()
        ->with(['/data/coolify/backups/volumes/test/historical.tar.gz'])
        ->andReturnTrue();
    Storage::shouldReceive('build')
        ->once()
        ->with(Mockery::on(fn (array $config): bool => $config['key'] === 'original-key'))
        ->andReturn($disk);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->set('delete_backup_s3', true)
        ->call('deleteBackup', $execution->id, 'password')
        ->assertDispatched('success');

    expect($execution->fresh())->toBeNull();
});

it('prevents another team from downloading a volume backup', function () {
    config(['app.maintenance.driver' => 'file']);
    $backupTeam = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($backupTeam);
    $backup = $volume->scheduledBackups()->create([
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

it('enables and disables volume S3 backups from the S3 title action', function () {
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
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        's3_storage_id' => $s3Storage->id,
    ]);

    $component = Livewire::test(VolumeBackups::class, [
        'storage' => $volume,
        'resource' => $application,
        'section' => 's3',
    ])
        ->assertSee('Enable S3')
        ->assertDontSee('You do not have permission to perform this action.')
        ->call('toggleS3')
        ->assertSet('saveToS3', true)
        ->assertSee('Disable S3');

    expect($backup->refresh()->save_s3)->toBeTrue();

    $component->call('toggleS3')->assertSet('saveToS3', false);

    expect($backup->refresh()->save_s3)->toBeFalse();
});

it('shows and saves volume S3 retention while S3 backups are disabled', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => false,
    ]);

    Livewire::test(VolumeBackups::class, [
        'storage' => $volume,
        'resource' => $application,
        'section' => 'retention',
    ])
        ->assertSee('S3 Storage Retention')
        ->set('retentionAmountS3', 12)
        ->set('retentionDaysS3', 30)
        ->set('retentionMaxStorageS3', 4.5)
        ->call('save')
        ->assertHasNoErrors();

    expect($backup->refresh()->save_s3)->toBeFalse()
        ->and($backup->retention_amount_s3)->toBe(12)
        ->and($backup->retention_days_s3)->toBe(30)
        ->and($backup->retention_max_storage_s3)->toBe(4.5);
});

it('allows team owners to edit volume backup retention settings', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);

    Livewire::test(VolumeBackups::class, [
        'storage' => $volume,
        'resource' => $application,
        'section' => 'retention',
    ])->assertDontSee('You do not have permission to perform this action.');
});

it('only updates S3 fields when toggling volume S3 backups', function () {
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
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        's3_storage_id' => $s3Storage->id,
    ]);

    Livewire::test(VolumeBackups::class, [
        'storage' => $volume,
        'resource' => $application,
        'section' => 's3',
    ])
        ->set('frequency', 'not a valid schedule')
        ->call('toggleS3')
        ->assertDispatched('success');

    expect($backup->refresh()->save_s3)->toBeTrue()
        ->and($backup->frequency)->toBe('daily');
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

function createApplicationBackupDirectory(Application $application, string $path = './uploads'): LocalFileVolume
{
    return LocalFileVolume::unguarded(fn () => LocalFileVolume::withoutEvents(fn () => LocalFileVolume::create([
        'uuid' => new_public_id(),
        'fs_path' => $path,
        'mount_path' => '/app/uploads',
        'is_directory' => true,
        'is_based_on_git' => false,
        'is_preview_suffix_enabled' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ])));
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
        ->assertSee('General')
        ->assertSee('Volume:')
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

    expect($backup->backupable->is($volume))->toBeTrue()
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
    $volume->scheduledBackups()->create([
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
        ->and($backup->fresh()->s3_storage_id)->toBe($s3Storage->id)
        ->and($backup->fresh()->disable_local_backup)->toBeFalse();
});

it('saves the selected volume backup S3 storage immediately while S3 is disabled', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $firstS3Storage = S3Storage::create([
        'name' => 'First storage',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $secondS3Storage = S3Storage::create([
        'name' => 'Second storage',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => false,
        's3_storage_id' => $firstS3Storage->id,
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application, 'section' => 's3'])
        ->set('s3StorageId', $secondS3Storage->id)
        ->assertDispatched('success');

    $backup = ScheduledVolumeBackup::query()->sole();
    expect($backup->save_s3)->toBeFalse()
        ->and($backup->s3_storage_id)->toBe($secondS3Storage->id);
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
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);

    $generalComponent = Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application]);
    preg_match('/<input\b(?=[^>]*wire:model=(?:"stopDuringBackup"|stopDuringBackup))[^>]*>/', $generalComponent->html(), $matches);
    expect($matches[0] ?? null)->not->toBeNull()
        ->and($matches[0])->toContain("wire:click='instantSave'");

    $s3Component = Livewire::test(VolumeBackups::class, [
        'storage' => $volume,
        'resource' => $application,
        'section' => 's3',
    ]);
    foreach (['disableLocalBackup'] as $property) {
        preg_match('/<input\b(?=[^>]*wire:model=(?:"'.$property.'"|'.$property.'))[^>]*>/', $s3Component->html(), $matches);
        expect($matches[0] ?? null)->not->toBeNull()
            ->and($matches[0])->toContain("wire:click='instantSave'");
    }

    $generalComponent->set('stopDuringBackup', true)->call('instantSave')->assertDispatched('success');
    expect($backup->refresh()->stop_during_backup)->toBeTrue();

    $generalComponent->set('stopDuringBackup', false)->call('instantSave')->assertDispatched('success');
    expect($backup->refresh()->stop_during_backup)->toBeFalse();
});

it('allows volume S3 backups to be disabled when no usable storage remains', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => null,
    ]);

    $component = Livewire::test(VolumeBackups::class, [
        'storage' => $volume,
        'resource' => $application,
        'section' => 's3',
    ])
        ->assertSet('saveToS3', true)
        ->assertSeeHtml('<h2>S3 storage</h2>')
        ->assertSeeText('No validated S3 storage')
        ->assertSeeHtml('href="'.route('storage.index').'"')
        ->assertSeeText('Open S3 storage')
        ->assertDontSee('Save')
        ->assertDontSee('Disable S3')
        ->assertDontSee('Disable Local Backup')
        ->call('toggleS3')
        ->assertDispatched('success')
        ->assertSet('saveToS3', false)
        ->assertDontSee('Enable S3');

    expect($backup->refresh()->save_s3)->toBeFalse()
        ->and($backup->s3_storage_id)->toBeNull();

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
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $s3Storage->id,
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

it('marks historical executions deleted when their recorded S3 storage is removed', function () {
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $originalStorage = S3Storage::create([
        'name' => 'Original storage',
        'region' => 'us-east-1',
        'key' => 'original-key',
        'secret' => 'secret',
        'bucket' => 'original-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
    ]);
    $newStorage = S3Storage::create([
        'name' => 'New storage',
        'region' => 'us-east-1',
        'key' => 'new-key',
        'secret' => 'secret',
        'bucket' => 'new-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $newStorage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $originalStorage->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/historical.tar.gz',
        's3_uploaded' => true,
        's3_cleanup_pending' => true,
    ]);

    $originalStorage->delete();

    expect($backup->fresh()->s3_storage_id)->toBe($newStorage->id)
        ->and($execution->fresh()->s3_storage_id)->toBeNull()
        ->and($execution->fresh()->s3_storage_deleted)->toBeTrue()
        ->and($execution->fresh()->s3_cleanup_pending)->toBeFalse();
});

it('queues a manual backup before a schedule has been saved', function () {
    Queue::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);

    $component = Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('backupNow')
        ->assertDispatched('success');

    $backup = ScheduledVolumeBackup::query()->sole();

    $component->assertRedirectToRoute('project.application.backup.executions', [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    expect($backup->enabled)->toBeFalse();
    Queue::assertPushed(VolumeBackupJob::class, fn (VolumeBackupJob $job) => $job->backup->is($backup));
});

it('queues a manual backup when its S3 storage has become unusable', function () {
    Queue::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $s3Storage = S3Storage::create([
        'name' => 'Unavailable storage',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => false,
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $parameters = [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'backup_uuid' => $backup->uuid,
    ];

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('backupNow')
        ->assertRedirectToRoute('project.application.backup.executions', $parameters);

    Queue::assertPushed(VolumeBackupJob::class, fn (VolumeBackupJob $job) => $job->backup->is($backup));
});

it('deletes local archives before deleting a volume backup schedule', function () {
    Process::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
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
        ->assertDispatched('success')
        ->assertRedirectToRoute('project.application.backup.index', [
            'project_uuid' => $application->project()->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ]);

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, 'archive.tar.gz'));
});

it('deletes a volume backup schedule without a password when two-step confirmation is disabled', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    InstanceSettings::get()->update(['disable_two_step_confirmation' => true]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('delete', '')
        ->assertDispatched('success');

    expect($backup->fresh())->toBeNull();
});

it('deletes S3 archives from the storage recorded on each execution', function () {
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $originalStorage = S3Storage::create([
        'name' => 'Original storage',
        'region' => 'us-east-1',
        'key' => 'original-key',
        'secret' => 'secret',
        'bucket' => 'original-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $newStorage = S3Storage::create([
        'name' => 'New storage',
        'region' => 'us-east-1',
        'key' => 'new-key',
        'secret' => 'secret',
        'bucket' => 'new-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $newStorage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $originalStorage->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/historical.tar.gz',
        'local_storage_deleted' => true,
        's3_uploaded' => true,
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')
        ->once()
        ->with(['/data/coolify/backups/volumes/test/historical.tar.gz'])
        ->andReturnTrue();
    Storage::shouldReceive('build')
        ->once()
        ->with(Mockery::on(fn (array $config): bool => $config['key'] === 'original-key'))
        ->andReturn($disk);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $application])
        ->call('delete', 'password')
        ->assertDispatched('success');

    expect($backup->fresh())->toBeNull();
});

it('refuses to delete a schedule while its backup is running', function () {
    Process::fake();
    $team = Team::factory()->create();
    signInForVolumeBackups($this, $team);
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
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
    $backup = $volume->scheduledBackups()->create([
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
    $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);

    expect(fn () => $volume->delete())->toThrow(RuntimeException::class, 'Delete this volume backup schedule');
    expect($volume->fresh())->not->toBeNull();
});

it('aborts storage deletion when scheduled backups exist', function () {
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $directory = createApplicationBackupDirectory($application);

    $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);
    $directory->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
    ]);

    expect(fn () => $volume->abortIfScheduledBackupsExist())
        ->toThrow(HttpException::class, 'Delete this volume backup schedule and its archives before deleting the volume.')
        ->and(fn () => $directory->abortIfScheduledBackupsExist())
        ->toThrow(HttpException::class, 'Delete this directory backup schedule and its archives before deleting the directory.');
});

it('deletes disabled volume backup archives before deleting their application', function () {
    Process::fake();
    Queue::fake();
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => false,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/application-delete.tar.gz',
    ]);
    $application->delete();

    (new DeleteResourceJob($application))->handle();

    expect($backup->fresh())->toBeNull()
        ->and($execution->fresh())->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, 'rm -f')
        && str_contains($process->command, 'application-delete.tar.gz'));
});

it('marks a running execution failed even when the job instance lost its execution state', function () {
    Process::fake();
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
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

it('archives a named volume using the server compression CPU percentage', function (int $compressionCpuPercentage) {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume, $server] = createVolumeBackupApplication($team);
    $server->settings->update(['backup_compression_cpu_percentage' => $compressionCpuPercentage]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => true,
        'retention_amount_locally' => 7,
        'retention_amount_s3' => 7,
        'timeout' => 3600,
    ]);

    Process::fake([
        '*docker ps -q*' => 'abc123',
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
        && str_contains($process->command, 'command -v pigz')
        && str_contains($process->command, 'pigz -3 -p')
        && str_contains($process->command, "\$(nproc) * {$compressionCpuPercentage} + 99")
        && str_contains($process->command, 'gzip -3')
        && str_contains($process->command, 'tar -I "$compressor" -cf -')
        && str_contains($process->command, '> ')
        && str_contains($process->command, '.tar.gz')
        && ! str_contains($process->command, ':/backup'));
})->with([
    'low' => 25,
    'high' => 75,
]);

it('logs the selected volume backup compressor in development', function (string $detectedCompressor) {
    config(['app.env' => 'local', 'broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'retention_amount_locally' => 7,
        'retention_days_locally' => 0,
        'retention_max_storage_locally' => 0,
        'retention_amount_s3' => 7,
        'retention_days_s3' => 0,
        'retention_max_storage_s3' => 0,
    ]);

    Process::fake([
        '*command -v pigz*' => $detectedCompressor,
        '*du -b*' => '128',
        '*' => '',
    ]);
    Log::spy();

    (new VolumeBackupJob($backup))->handle();

    Log::shouldHaveReceived('info')->once()->with(
        'Volume backup compressor selected',
        Mockery::on(fn (array $context): bool => $context['compressor'] === $detectedCompressor
            && $context['backup_id'] === $backup->id
            && $context['cpu_percentage'] === 25),
    );
})->with([
    'pigz' => 'pigz -3 -p 4',
    'gzip fallback' => 'gzip -3',
]);

it('keeps the upload destination on the volume backup execution', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $s3Storage = S3Storage::create([
        'name' => 'Execution destination',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => $s3Storage->id,
    ]);
    $sshDisk = Storage::fake('ssh-keys');
    $disk = Mockery::mock();
    $disk->shouldReceive('files')->zeroOrMoreTimes()->andReturn([]);
    $disk->shouldReceive('delete')->zeroOrMoreTimes()->andReturnTrue();
    Storage::shouldReceive('disk')->with('ssh-keys')->andReturn($sshDisk);
    Storage::shouldReceive('build')->zeroOrMoreTimes()->andReturn($disk);
    Process::fake([
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();

    expect($execution->s3_storage_id)->toBe($s3Storage->id)
        ->and($execution->s3->is($s3Storage))->toBeTrue();
});

it('removes local volume backups older than the configured retention days', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
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
    $backup = $volume->scheduledBackups()->create([
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

it('removes retained S3 archives from the storage recorded on each execution', function () {
    $team = Team::factory()->create();
    [$application, $volume, $server] = createVolumeBackupApplication($team);
    $originalStorage = S3Storage::create([
        'name' => 'Original storage',
        'region' => 'us-east-1',
        'key' => 'original-key',
        'secret' => 'secret',
        'bucket' => 'original-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $newStorage = S3Storage::create([
        'name' => 'New storage',
        'region' => 'us-east-1',
        'key' => 'new-key',
        'secret' => 'secret',
        'bucket' => 'new-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $newStorage->id,
        'frequency' => 'daily',
        'save_s3' => true,
        'retention_amount_locally' => 0,
        'retention_days_locally' => 0,
        'retention_max_storage_locally' => 0,
        'retention_amount_s3' => 1,
        'retention_days_s3' => 0,
        'retention_max_storage_s3' => 0,
    ]);
    $oldExecution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $originalStorage->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/old.tar.gz',
        's3_uploaded' => true,
        'created_at' => now()->subDay(),
    ]);
    ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $newStorage->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/new.tar.gz',
        's3_uploaded' => true,
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->with(['/data/coolify/backups/volumes/test/old.tar.gz'])->andReturnTrue();
    Storage::shouldReceive('build')
        ->once()
        ->with(Mockery::on(fn (array $config): bool => $config['key'] === 'original-key'))
        ->andReturn($disk);
    $job = new VolumeBackupJob($backup);
    $method = (new ReflectionClass($job))->getMethod('removeExpiredBackups');

    $method->invoke($job, $server);

    expect($oldExecution->fresh()->s3_storage_deleted)->toBeTrue();
});

it('does not query execution history when local retention is unlimited', function () {
    $team = Team::factory()->create();
    [$application, $volume, $server] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => false,
        'retention_amount_locally' => 0,
        'retention_days_locally' => 0,
        'retention_max_storage_locally' => 0,
    ]);
    ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/unlimited.tar.gz',
    ]);
    $job = new VolumeBackupJob($backup);
    $method = (new ReflectionClass($job))->getMethod('removeExpiredBackups');
    DB::enableQueryLog();
    DB::flushQueryLog();

    $method->invoke($job, $server);

    $executionQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'scheduled_volume_backup_executions'))
        ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'));

    expect($executionQueries)->toBeEmpty();
});

it('keeps a successful backup successful when retention cleanup fails', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
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
    $backup = $volume->scheduledBackups()->create([
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
    $backup = $volume->scheduledBackups()->create([
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

it('archives an application directory mount from its resolved host path', function () {
    config(['broadcasting.default' => 'null']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    $team = Team::factory()->create();
    [$application] = createVolumeBackupApplication($team);
    $directory = LocalFileVolume::unguarded(fn () => LocalFileVolume::withoutEvents(fn () => LocalFileVolume::create([
        'uuid' => new_public_id(),
        'fs_path' => './uploads',
        'mount_path' => '/app/uploads',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ])));
    $backup = $directory->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'stop_during_backup' => true,
    ]);
    $source = $application->workdir().'/uploads';

    Process::fake([
        '*docker ps -q*' => 'abc123',
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    expect(ScheduledVolumeBackupExecution::query()->sole()->status)->toBe('success');
    Process::assertRan(fn ($process) => str_contains($process->command, 'test -d '.escapeshellarg($source)));
    Process::assertRan(fn ($process) => str_contains($process->command, $source)
        && str_contains($process->command, ':/volume:ro')
        && str_contains($process->command, 'directory-uploads-'));
    Process::assertRan(fn ($process) => str_contains($process->command, "grep -Fqx -- '".$source."'"));
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker stop')
        && str_contains($process->command, 'docker start'));
});

it('retries container recovery when a timed out backup left a container stopped', function () {
    Queue::fake();
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
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
    $backup = $volume->scheduledBackups()->create([
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
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $s3Storage->id,
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

it('cleans an interrupted upload from the execution S3 storage after a schedule switch', function () {
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $originalStorage = S3Storage::create([
        'name' => 'Original storage',
        'region' => 'us-east-1',
        'key' => 'original-key',
        'secret' => 'secret',
        'bucket' => 'original-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $newStorage = S3Storage::create([
        'name' => 'New storage',
        'region' => 'us-east-1',
        'key' => 'new-key',
        'secret' => 'secret',
        'bucket' => 'new-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $newStorage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $originalStorage->id,
        'status' => 'failed',
        'filename' => '/data/coolify/backups/volumes/test/interrupted.tar.gz',
        's3_cleanup_pending' => true,
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('build')
        ->once()
        ->with(Mockery::on(fn (array $config): bool => $config['key'] === 'original-key'))
        ->andReturn($disk);

    VolumeBackupRecoveryJob::cleanupS3Upload($execution);

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
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        's3_storage_id' => $s3Storage->id,
        'frequency' => 'daily',
        'save_s3' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        's3_storage_id' => $s3Storage->id,
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
    $backup = $volume->scheduledBackups()->create([
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

it('dispatches due scheduled directory backups', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow('2026-07-15 12:00:00');
    Queue::fake();
    $team = Team::factory()->create();
    [$application, $volume, $server] = createVolumeBackupApplication($team);
    $directory = createApplicationBackupDirectory($application);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $backup = $directory->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    Cache::forget("scheduled-volume-backup:{$backup->id}");

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(
        VolumeBackupJob::class,
        fn (VolumeBackupJob $job) => $job->backup->is($backup),
    );
});

it('retains scheduled volume backups and archive metadata when the server is missing', function () {
    config(['constants.coolify.self_hosted' => true]);
    Queue::fake();
    $team = Team::factory()->create();
    [$application, $volume] = createVolumeBackupApplication($team);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/volumes/test/archive.tar.gz',
    ]);
    $application->update([
        'destination_id' => null,
        'destination_type' => null,
    ]);

    (new ScheduledJobManager)->handle();

    expect($backup->fresh())->not->toBeNull()
        ->and($execution->fresh())->not->toBeNull()
        ->and($execution->fresh()->filename)->toBe('/data/coolify/backups/volumes/test/archive.tar.gz');
    Queue::assertNotPushed(VolumeBackupJob::class);
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
    $backup = $volume->scheduledBackups()->create([
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
