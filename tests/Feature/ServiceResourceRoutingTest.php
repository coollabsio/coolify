<?php

use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Livewire\Project\Database\Import as DatabaseImport;
use App\Livewire\Project\Service\Heading;
use App\Livewire\Project\Service\VolumeBackup\Index as ServiceVolumeBackupIndex;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('cache.default', 'array');
    Config::set('app.maintenance.store', 'array');
    Config::set('queue.default', 'sync');

    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::factory()->create([
        'server_id' => $this->serverA->id,
        'network' => 'team-a-network',
    ]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);

    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::factory()->create([
        'server_id' => $this->serverB->id,
        'network' => 'team-b-network',
    ]);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    $this->otherService = Service::factory()->create([
        'server_id' => $this->serverB->id,
        'destination_id' => $this->destinationB->id,
        'destination_type' => $this->destinationB->getMorphClass(),
        'environment_id' => $this->environmentB->id,
    ]);
    $this->otherServiceApplication = ServiceApplication::create([
        'service_id' => $this->otherService->id,
        'name' => 'other-app',
        'image' => 'nginx:alpine',
    ]);
    $this->otherServiceDatabase = ServiceDatabase::create([
        'service_id' => $this->otherService->id,
        'name' => 'other-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    $this->ownService = Service::factory()->create([
        'server_id' => $this->serverA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
        'environment_id' => $this->environmentA->id,
    ]);
    $this->ownServiceDatabase = ServiceDatabase::create([
        'service_id' => $this->ownService->id,
        'name' => 'own-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('does not open service application detail route from another team', function () {
    $this->withoutExceptionHandling();

    $this->get(route('project.service.index', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->otherService->uuid,
        'stack_service_uuid' => $this->otherServiceApplication->uuid,
    ]));
})->throws(NotFoundHttpException::class);

test('does not open service database backups route from another team', function () {
    $this->withoutExceptionHandling();

    $this->get(route('project.service.database.backups', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->otherService->uuid,
        'stack_service_uuid' => $this->otherServiceDatabase->uuid,
    ]));
})->throws(NotFoundHttpException::class);

test('does not open service import backup route from another team', function () {
    $this->get(route('project.service.import-backup', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->otherService->uuid,
    ]))->assertForbidden();
});

test('does not resolve service database import component from another team', function () {
    $component = app(DatabaseImport::class);
    $component->parameters = [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->otherService->uuid,
        'stack_service_uuid' => $this->otherServiceDatabase->uuid,
    ];

    $component->getContainers();
})->throws(ModelNotFoundException::class);

test('service heading does not hydrate with another team service', function () {
    Livewire::test(Heading::class, ['service' => $this->otherService]);
})->throws(ModelNotFoundException::class);

test('owner can still hydrate service heading with own service', function () {
    Livewire::test(Heading::class, [
        'service' => $this->ownService,
        'parameters' => [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
            'service_uuid' => $this->ownService->uuid,
        ],
    ])
        ->assertOk();
});

test('legacy service database backup detail urls redirect to unified backup views', function () {
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
        'save_s3' => true,
    ]);
    $listUrl = route('project.service.database.backups', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
        'stack_service_uuid' => $this->ownServiceDatabase->uuid,
    ]);
    $generalUrl = $listUrl.'/'.$backup->uuid;
    $parameters = [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ];

    $this->get($generalUrl)->assertRedirect(route('project.service.volume-backups.index', $parameters));
    $this->get($generalUrl.'/s3')->assertRedirect(route('project.service.volume-backups.index', $parameters));
    $this->get($generalUrl.'/retention')->assertRedirect(route('project.service.volume-backups.index', $parameters));
    $this->get($generalUrl.'/danger')->assertRedirect(route('project.service.volume-backups.index', $parameters));
    $this->get($generalUrl.'/executions')->assertRedirect(route('project.service.volume-backups.index', $parameters));
});

test('legacy service database backup list redirects to unified service backups', function () {
    $legacyUrl = route('project.service.database.backups', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
        'stack_service_uuid' => $this->ownServiceDatabase->uuid,
    ]);
    $centralBackupsUrl = route('project.service.volume-backups.index', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]);

    $this->get($legacyUrl)->assertRedirect($centralBackupsUrl);
});

test('service backup schedules open in place from the unified view', function () {
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);

    $this->get(route('project.service.volume-backups.index', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]))
        ->assertOk()
        ->assertSee("wire:click=\"openSchedule('{$backup->uuid}')\"", false);
});

test('service database backup schedules open in the Livewire component', function () {
    Queue::fake();
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);

    Livewire::test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])
        ->call('openSchedule', $backup->uuid)
        ->assertSet('scheduleModalOpen', true)
        ->assertSet('selectedDatabaseBackup.uuid', $backup->uuid)
        ->assertSet('selectedVolumeBackup', null);
});

test('service database backups can be queued from the Livewire component', function () {
    Queue::fake();
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);

    Livewire::test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])
        ->call('backupNow', 'database', $backup->uuid)
        ->assertDispatched('success', 'Backup queued.');

    Queue::assertPushed(DatabaseBackupJob::class, fn (DatabaseBackupJob $job): bool => $job->backup->is($backup));
});

test('service storage backups can be queued from the Livewire component', function () {
    Queue::fake();
    $volume = LocalPersistentVolume::create([
        'name' => 'service-data',
        'mount_path' => '/data',
        'resource_id' => $this->ownServiceDatabase->id,
        'resource_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
    ]);

    Livewire::test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])
        ->call('backupNow', 'storage', $backup->uuid)
        ->assertDispatched('success', 'Backup queued.');

    Queue::assertPushed(VolumeBackupJob::class, fn (VolumeBackupJob $job): bool => $job->backup->is($backup));
});

test('service backup executions combine database execution history', function () {
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);
    $execution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'database_name' => 'coolify',
        'size' => 2048,
        'finished_at' => now(),
    ]);

    $this->get(route('project.service.volume-backups.index', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]))
        ->assertOk()
        ->assertSee('own-db')
        ->assertSee('Success')
        ->assertSee('2 KB');

    $this->get(route('project.service.volume-backups.index', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]))->assertSee("wire:click=\"openExecution('{$execution->uuid}')\"", false);
});

test('service import backup page selects from compatible databases', function () {
    $secondDatabase = ServiceDatabase::create([
        'service_id' => $this->ownService->id,
        'name' => 'analytics-db',
        'image' => 'mysql:8',
        'custom_type' => 'mysql',
    ]);
    $importUrl = route('project.service.import-backup', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]);

    $this->get($importUrl)
        ->assertOk()
        ->assertSee('Import Backup')
        ->assertSee('own-db')
        ->assertSee('analytics-db');

    $this->get($importUrl.'/'.$secondDatabase->uuid)
        ->assertOk()
        ->assertSee('analytics-db')
        ->assertSee('Start the database first');
});

test('service import backup redirects when exactly one compatible database exists', function () {
    $this->get(route('project.service.import-backup', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]))->assertRedirectToRoute('project.service.import-backup.database', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
        'stack_service_uuid' => $this->ownServiceDatabase->uuid,
    ]);
});

test('service import backup excludes unsupported databases', function () {
    $unsupportedDatabase = ServiceDatabase::create([
        'service_id' => $this->ownService->id,
        'name' => 'cache-db',
        'image' => 'redis:7-alpine',
        'custom_type' => 'redis',
    ]);

    $this->get(route('project.service.import-backup', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]))
        ->assertRedirectToRoute('project.service.import-backup.database', [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
            'service_uuid' => $this->ownService->uuid,
            'stack_service_uuid' => $this->ownServiceDatabase->uuid,
        ]);

    $this->get(route('project.service.import-backup.database', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
        'stack_service_uuid' => $unsupportedDatabase->uuid,
    ]))->assertNotFound();
});

test('service import backup opens the selected compatible database', function () {
    $secondDatabase = ServiceDatabase::create([
        'service_id' => $this->ownService->id,
        'name' => 'analytics-db',
        'image' => 'mysql:8',
        'custom_type' => 'mysql',
    ]);

    $this->get(route('project.service.import-backup.database', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
        'stack_service_uuid' => $secondDatabase->uuid,
    ]))
        ->assertOk()
        ->assertSee('analytics-db')
        ->assertSee('Start the database first');
});

test('service import backup requires update authorization for the service and selected database', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->teamA, ['role' => 'member']);
    $this->actingAs($member);

    $parameters = [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ];

    $this->get(route('project.service.import-backup', $parameters))->assertForbidden();
    $this->get(route('project.service.import-backup.database', [
        ...$parameters,
        'stack_service_uuid' => $this->ownServiceDatabase->uuid,
    ]))->assertForbidden();
});

test('legacy service database import redirects to the service import page with its database selected', function () {
    $legacyUrl = route('project.service.database.import', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
        'stack_service_uuid' => $this->ownServiceDatabase->uuid,
    ]);
    $selectedImportUrl = route('project.service.import-backup.database', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
        'stack_service_uuid' => $this->ownServiceDatabase->uuid,
    ]);

    $this->get($legacyUrl)->assertRedirect($selectedImportUrl);
});

test('service storage backups page includes schedules from all compose databases', function () {
    $secondDatabase = ServiceDatabase::create([
        'service_id' => $this->ownService->id,
        'name' => 'analytics-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    $backups = collect([$this->ownServiceDatabase, $secondDatabase])->map(function (ServiceDatabase $database) {
        return ScheduledDatabaseBackup::create([
            'team_id' => $this->teamA->id,
            'description' => $database->name.' backup',
            'frequency' => 'daily',
            'database_id' => $database->id,
            'database_type' => $database->getMorphClass(),
        ]);
    });

    $this->get(route('project.service.volume-backups.index', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]))
        ->assertOk()
        ->assertSee('>Database</span>', false)
        ->assertSee('own-db')
        ->assertSee('analytics-db')
        ->assertSee("wire:click=\"openSchedule('{$backups->first()->uuid}')\"", false)
        ->assertSee("wire:click=\"backupNow('database', '{$backups->first()->uuid}')\"", false);
});
