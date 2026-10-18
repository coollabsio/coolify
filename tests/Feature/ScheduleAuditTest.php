<?php

use App\Livewire\Project\Application\Backup\Create as CreateApplicationVolumeBackup;
use App\Livewire\Project\Database\BackupEdit;
use App\Livewire\Project\Database\CreateScheduledBackup;
use App\Livewire\Project\Database\ScheduledBackups;
use App\Livewire\Project\Service\VolumeBackup\Create as CreateServiceVolumeBackup;
use App\Livewire\Project\Shared\ScheduledTask\Add as AddScheduledTask;
use App\Livewire\Project\Shared\ScheduledTask\Show as ShowScheduledTask;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Livewire\SettingsBackup;
use App\Livewire\Storage\Resources as StorageResources;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    $this->withoutVite();
    Server::flushIdentityMap();

    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.20',
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

afterEach(function () {
    Event::forget(RouteMatched::class);
});

function bindScheduleAuditTaskRoute(Application $application, ScheduledTask $task): void
{
    $application->loadMissing('environment.project');

    Event::listen(RouteMatched::class, function (RouteMatched $event) use ($application, $task): void {
        $event->route->setParameter('task_uuid', $task->uuid);
        $event->route->setParameter('project_uuid', $application->environment->project->uuid);
        $event->route->setParameter('environment_uuid', $application->environment->uuid);
        $event->route->setParameter('application_uuid', $application->uuid);
    });
}

function createScheduleAuditTask(Team $team, Application $application): ScheduledTask
{
    return ScheduledTask::factory()->create([
        'team_id' => $team->id,
        'application_id' => $application->id,
        'name' => 'nightly-job',
        'command' => 'echo nightly',
        'frequency' => '0 0 * * *',
        'container' => '',
        'timeout' => 300,
        'enabled' => true,
    ]);
}

function createScheduleAuditPostgres(Environment $environment, StandaloneDocker $destination): StandalonePostgresql
{
    return StandalonePostgresql::create([
        'name' => 'schedule-audit-pg',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createScheduleAuditDatabaseBackup(Team $team, StandalonePostgresql $database, array $overrides = []): ScheduledDatabaseBackup
{
    return ScheduledDatabaseBackup::create(array_merge([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        's3_storage_id' => null,
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $team->id,
    ], $overrides));
}

function createScheduleAuditS3(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}

function createScheduleAuditVolume(Application $application): LocalPersistentVolume
{
    return LocalPersistentVolume::create([
        'name' => 'schedule-audit-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
}

function scheduleAuditEvent(string $event): AuditEvent
{
    return AuditEvent::query()->where('event', $event)->sole();
}

test('creating a scheduled task records an audit event without the command', function () {
    Livewire::test(AddScheduledTask::class, [
        'id' => (string) $this->application->id,
        'type' => 'application',
        'containerNames' => collect(),
    ])
        ->set('name', 'backup-job')
        ->set('command', 'curl -H "Authorization: Bearer super-secret" https://example.com')
        ->set('frequency', '0 0 * * *')
        ->set('timeout', 300)
        ->call('submit')
        ->assertHasNoErrors();

    $task = ScheduledTask::query()->sole();
    $event = scheduleAuditEvent('ui.scheduled_task.created');

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->resource_uuid)->toBe($task->uuid)
        ->and($event->metadata['resource_type'])->toBe('application')
        ->and($event->metadata['resource_uuid'])->toBe($this->application->uuid)
        ->and(json_encode($event->metadata))->not->toContain('super-secret');
});

test('updating a scheduled task records changed field names only', function () {
    $task = createScheduleAuditTask($this->team, $this->application);
    bindScheduleAuditTaskRoute($this->application, $task);

    Livewire::test(ShowScheduledTask::class)
        ->set('command', 'echo token=super-secret')
        ->set('timeout', 600)
        ->call('submit');

    $event = scheduleAuditEvent('ui.scheduled_task.updated');

    expect($event->resource_uuid)->toBe($task->uuid)
        ->and($event->metadata['changed_fields'])->toEqualCanonicalizing(['command', 'timeout'])
        ->and(json_encode($event->metadata))->not->toContain('super-secret');
});

test('saving a scheduled task without changes records no audit event', function () {
    $task = createScheduleAuditTask($this->team, $this->application);
    bindScheduleAuditTaskRoute($this->application, $task);

    Livewire::test(ShowScheduledTask::class)
        ->call('submit')
        ->call('instantSave');

    expect(AuditEvent::query()->where('event', 'ui.scheduled_task.updated')->exists())->toBeFalse();
});

test('toggling and deleting a scheduled task record audit events', function () {
    $task = createScheduleAuditTask($this->team, $this->application);
    bindScheduleAuditTaskRoute($this->application, $task);

    Livewire::test(ShowScheduledTask::class)
        ->call('toggleEnabled')
        ->call('delete');

    expect(scheduleAuditEvent('ui.scheduled_task.updated')->metadata['changed_fields'])->toBe(['enabled'])
        ->and(scheduleAuditEvent('ui.scheduled_task.deleted')->resource_uuid)->toBe($task->uuid)
        ->and($task->fresh())->toBeNull();
});

test('creating a database backup schedule records an audit event', function () {
    $database = createScheduleAuditPostgres($this->environment, $this->destination);

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', 'daily')
        ->call('submit');

    $backup = ScheduledDatabaseBackup::query()->sole();
    $event = scheduleAuditEvent('ui.database.backup_schedule_created');

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->resource_uuid)->toBe($database->uuid)
        ->and($event->metadata['backup_uuid'])->toBe($backup->uuid)
        ->and($event->metadata['save_s3'])->toBeFalse();
});

test('updating a database backup schedule records changed fields and skips unchanged saves', function () {
    $database = createScheduleAuditPostgres($this->environment, $this->destination);
    $backup = createScheduleAuditDatabaseBackup($this->team, $database);

    $component = Livewire::test(BackupEdit::class, [
        'backup' => $backup->fresh(),
        'availableS3Storages' => $this->team->s3s,
    ]);

    $component->call('submit');
    expect(AuditEvent::query()->where('event', 'ui.database.backup_schedule_updated')->exists())->toBeFalse();

    $component->set('frequency', '0 3 * * *')->call('submit');
    $component->call('toggleEnabled');

    $events = AuditEvent::query()->where('event', 'ui.database.backup_schedule_updated')->oldest('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->resource_uuid)->toBe($database->uuid)
        ->and($events[0]->metadata['backup_uuid'])->toBe($backup->uuid)
        ->and($events[0]->metadata['changed_fields'])->toBe(['frequency'])
        ->and($events[1]->metadata['changed_fields'])->toBe(['enabled']);
});

test('deleting a backup schedule from the backup list records an audit event', function () {
    $database = createScheduleAuditPostgres($this->environment, $this->destination);
    $backup = createScheduleAuditDatabaseBackup($this->team, $database);

    Event::listen(RouteMatched::class, function (RouteMatched $event) use ($database): void {
        $event->route->setParameter('project_uuid', $this->project->uuid);
        $event->route->setParameter('environment_uuid', $this->environment->uuid);
        $event->route->setParameter('database_uuid', $database->uuid);
    });

    Livewire::test(ScheduledBackups::class, ['database' => $database])
        ->call('delete', $backup->id);

    $event = scheduleAuditEvent('ui.database.backup_schedule_deleted');

    expect($backup->fresh())->toBeNull()
        ->and($event->resource_uuid)->toBe($database->uuid)
        ->and($event->metadata['backup_uuid'])->toBe($backup->uuid);
});

test('disabling or moving S3 storage for backups records audit events', function () {
    $source = createScheduleAuditS3($this->team, 'Source S3');
    $destination = createScheduleAuditS3($this->team, 'Destination S3');
    $database = createScheduleAuditPostgres($this->environment, $this->destination);
    $databaseBackup = createScheduleAuditDatabaseBackup($this->team, $database, [
        'save_s3' => true,
        's3_storage_id' => $source->id,
    ]);
    $volume = createScheduleAuditVolume($this->application);
    $volumeBackup = $volume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => $source->id,
    ]);

    Livewire::test(StorageResources::class, ['storage' => $source])
        ->call('disableS3', $databaseBackup->id)
        ->set("selectedVolumeStorages.{$volumeBackup->id}", $destination->id)
        ->call('moveVolumeBackup', $volumeBackup->id);

    $databaseEvent = scheduleAuditEvent('ui.database.backup_schedule_updated');
    $volumeEvent = scheduleAuditEvent('ui.volume_backup.schedule_set');

    expect($databaseEvent->resource_uuid)->toBe($database->uuid)
        ->and($databaseEvent->metadata['changed_fields'])->toEqualCanonicalizing(['save_s3', 's3_storage_id'])
        ->and($volumeEvent->metadata['resource_type'])->toBe('application')
        ->and($volumeEvent->metadata['resource_uuid'])->toBe($this->application->uuid)
        ->and($volumeEvent->metadata['storage_uuid'])->toBe($volume->uuid)
        ->and($volumeEvent->metadata['backup_uuid'])->toBe($volumeBackup->uuid)
        ->and($volumeEvent->metadata['changed_fields'])->toBe(['s3_storage_id']);
});

test('configuring the instance database backup records an audit event', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $rootTeam->id]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $rootTeam->id,
        'private_key_id' => $privateKey->id,
        'ip' => '127.0.0.1',
    ]);
    StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail()->forceFill(['id' => 0])->save();

    $admin = User::factory()->create();
    $rootTeam->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $rootTeam]);

    Process::fake([
        '*' => Process::result(output: json_encode([[
            'Config' => ['Env' => ['POSTGRES_PASSWORD=instance-secret', 'POSTGRES_USER=coolify', 'POSTGRES_DB=coolify']],
        ]])),
    ]);

    Livewire::test(SettingsBackup::class)
        ->call('addCoolifyDatabase')
        ->assertHasNoErrors();

    $database = StandalonePostgresql::query()->where('name', 'coolify-db')->sole();
    $event = scheduleAuditEvent('ui.database.backup_schedule_created');

    expect($event->team_id)->toBe(0)
        ->and($event->resource_uuid)->toBe($database->uuid)
        ->and(json_encode($event->metadata))->not->toContain('instance-secret');
});

test('creating an application storage backup schedule records an audit event', function () {
    $volume = createScheduleAuditVolume($this->application);

    Livewire::test(CreateApplicationVolumeBackup::class, [
        'application' => $this->application,
        'selectedTargetKey' => 'volume:'.$volume->id,
    ])
        ->set('frequency', 'daily')
        ->call('submit');

    $backup = ScheduledVolumeBackup::query()->sole();
    $event = scheduleAuditEvent('ui.volume_backup.schedule_set');

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->metadata['resource_type'])->toBe('application')
        ->and($event->metadata['resource_uuid'])->toBe($this->application->uuid)
        ->and($event->metadata['storage_uuid'])->toBe($volume->uuid)
        ->and($event->metadata['backup_uuid'])->toBe($backup->uuid)
        ->and($event->metadata['created'])->toBeTrue();
});

test('creating a service storage backup schedule records an audit event', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $serviceApplication = ServiceApplication::create([
        'uuid' => new_public_id(),
        'name' => 'directus',
        'service_id' => $service->id,
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => $service->uuid.'_directus-uploads',
        'mount_path' => '/directus/uploads',
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ]);

    Livewire::test(CreateServiceVolumeBackup::class, ['service' => $service])
        ->set('targetKey', 'volume:'.$volume->id)
        ->set('frequency', 'daily')
        ->call('submit');

    $event = scheduleAuditEvent('ui.volume_backup.schedule_set');

    expect($event->metadata['resource_type'])->toBe('service')
        ->and($event->metadata['resource_uuid'])->toBe($service->uuid)
        ->and($event->metadata['storage_uuid'])->toBe($volume->uuid);
});

test('updating, toggling, and deleting a storage backup schedule record audit events', function () {
    $volume = createScheduleAuditVolume($this->application);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'enabled' => true,
    ]);

    $component = Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $this->application]);

    $component->call('save');
    expect(AuditEvent::query()->where('event', 'ui.volume_backup.schedule_set')->exists())->toBeFalse();

    $component->set('frequency', 'hourly')->call('save');
    $component->call('toggleEnabled');
    $component->call('delete', 'password');

    $setEvents = AuditEvent::query()->where('event', 'ui.volume_backup.schedule_set')->oldest('id')->get();
    $deleteEvent = scheduleAuditEvent('ui.volume_backup.schedule_deleted');

    expect($setEvents)->toHaveCount(2)
        ->and($setEvents[0]->metadata['changed_fields'])->toBe(['frequency'])
        ->and($setEvents[0]->metadata['created'])->toBeFalse()
        ->and($setEvents[1]->metadata['changed_fields'])->toBe(['enabled'])
        ->and($deleteEvent->metadata['backup_uuid'])->toBe($backup->uuid)
        ->and($deleteEvent->metadata['storage_uuid'])->toBe($volume->uuid)
        ->and($backup->fresh())->toBeNull();
});
