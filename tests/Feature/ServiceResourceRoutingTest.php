<?php

use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Livewire\Project\Database\Import as DatabaseImport;
use App\Livewire\Project\Service\BackupExecutions;
use App\Livewire\Project\Service\Heading;
use App\Livewire\Project\Service\VolumeBackup\Index as ServiceVolumeBackupIndex;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
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
    $this->ownServiceDatabase->update(['status' => 'running:healthy']);
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
        ->assertSee("wire:click.stop=\"backupNow('database', '{$backups->first()->uuid}')\"", false);
});

test('service backup settings open automatically from the creation redirect', function () {
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);

    Livewire::withQueryParams(['backup_uuid' => $backup->uuid])
        ->test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])
        ->assertSet('scheduleModalOpen', true)
        ->assertSet('selectedDatabaseBackup.uuid', $backup->uuid)
        ->assertSee('S3');
});

test('service backup settings reject a backup belonging to another team', function () {
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamB->id,
        'frequency' => 'daily',
        'database_id' => $this->otherServiceDatabase->id,
        'database_type' => $this->otherServiceDatabase->getMorphClass(),
    ]);

    $this->expectException(ModelNotFoundException::class);

    Livewire::withQueryParams(['backup_uuid' => $backup->uuid])
        ->test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService]);
});

test('service backups have explicit settings actions for database and storage schedules', function () {
    $databaseBackup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'service-data',
        'mount_path' => '/data',
        'resource_id' => $this->ownServiceDatabase->id,
        'resource_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);
    $volumeBackup = $volume->scheduledBackups()->create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
    ]);

    $html = Livewire::test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])->html();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $buttons = $xpath->query('//button[contains(., "Settings")]');
    $actions = [];
    foreach ($buttons as $button) {
        $actions[] = $button->getAttribute('wire:click.stop');
    }

    expect($actions)->toContain("openSchedule('{$databaseBackup->uuid}')", "openSchedule('{$volumeBackup->uuid}')");

    foreach (['database' => $databaseBackup, 'storage' => $volumeBackup] as $type => $backup) {
        $backupAction = "wire:click.stop=\"backupNow('{$type}', '{$backup->uuid}')\"";
        $settingsAction = "wire:click.stop=\"openSchedule('{$backup->uuid}')\"";
        expect(strpos($html, $backupAction))->toBeLessThan(strpos($html, $settingsAction));
    }

});

test('members cannot open service backup settings', function () {
    $this->userA->teams()->updateExistingPivot($this->teamA->id, ['role' => 'member']);
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);

    Livewire::withQueryParams(['backup_uuid' => $backup->uuid])
        ->test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])
        ->assertForbidden();
});

test('closing service backup settings clears the backup query parameter', function () {
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);

    $component = Livewire::withQueryParams(['backup_uuid' => $backup->uuid, 'search' => 'own-db'])
        ->test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])
        ->assertSet('scheduleModalOpen', true)
        ->assertSet('backupUuid', $backup->uuid);

    expect($component->effects['url']['backupUuid'])
        ->toMatchArray(['as' => 'backup_uuid', 'use' => 'replace', 'except' => '']);

    $component->dispatch('modalClosed')
        ->assertSet('scheduleModalOpen', false)
        ->assertSet('selectedDatabaseBackup', null)
        ->assertSet('selectedVolumeBackup', null)
        ->assertSet('backupUuid', '')
        ->assertSet('search', 'own-db')
        ->assertNoRedirect();
});

test('service execution history paginates both backup types without truncating older runs', function () {
    $schedule = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);
    $databaseExecutions = collect(range(1, 105))->map(fn ($index) => ScheduledDatabaseBackupExecution::forceCreate([
        'scheduled_database_backup_id' => $schedule->id,
        'status' => 'success',
        'created_at' => now()->subMinutes($index),
    ]));
    $volume = LocalPersistentVolume::create([
        'name' => 'service-data',
        'mount_path' => '/data',
        'resource_id' => $this->ownServiceDatabase->id,
        'resource_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);
    $volumeSchedule = $volume->scheduledBackups()->create(['team_id' => $this->teamA->id, 'frequency' => 'daily']);
    $volumeExecution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $volumeSchedule->id,
        'status' => 'success',
    ]);

    $component = Livewire::test(BackupExecutions::class, ['service' => $this->ownService])
        ->assertViewHas('executions', function ($executions) use ($volumeExecution, $databaseExecutions) {
            expect($executions)->toBeInstanceOf(LengthAwarePaginator::class)
                ->and($executions->total())->toBe(106)
                ->and($executions->count())->toBe(10)
                ->and($executions->first()['uuid'])->toBe($volumeExecution->uuid)
                ->and($executions->last()['uuid'])->toBe($databaseExecutions[8]->uuid);

            return true;
        })
        ->assertSeeHtml('aria-label="Next page"');

    $component->call('openExecution', $volumeExecution->uuid)
        ->assertSet('selectedExecution.uuid', $volumeExecution->uuid)
        ->call('closeExecutionModal');
    $component->call('nextPage', 'executionsPage')
        ->assertViewHas('executions', fn ($executions) => $executions->currentPage() === 2 && $executions->first()['uuid'] === $databaseExecutions[9]->uuid);
    $component->call('setPage', 11, 'executionsPage')
        ->assertViewHas('executions', fn ($executions) => $executions->count() === 6 && $executions->last()['uuid'] === $databaseExecutions->last()->uuid)
        ->call('openExecution', $databaseExecutions->last()->uuid)
        ->assertSet('executionModalOpen', true)
        ->assertSet('selectedExecution.uuid', $databaseExecutions->last()->uuid);
    $component->set('perPage', 25)
        ->assertViewHas('executions', fn ($executions) => $executions->currentPage() === 1 && $executions->count() === 25);
    $component->call('setPage', 999, 'executionsPage')
        ->assertViewHas('executions', fn ($executions) => $executions->currentPage() === 5 && $executions->count() === 6);
    $component->set('perPage', 1000)->assertSet('perPage', 100);
    $component->set('perPage', 0)->assertSet('perPage', 1);
});

test('service execution pagination excludes other teams and denies opening their runs', function (string $type) {
    $schedule = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamB->id,
        'frequency' => 'daily',
        'database_id' => $this->otherServiceDatabase->id,
        'database_type' => $this->otherServiceDatabase->getMorphClass(),
    ]);
    $execution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $schedule->id,
        'status' => 'success',
    ]);

    if ($type === 'storage') {
        $volume = LocalPersistentVolume::create([
            'name' => 'other-service-data',
            'mount_path' => '/data',
            'resource_id' => $this->otherServiceDatabase->id,
            'resource_type' => $this->otherServiceDatabase->getMorphClass(),
        ]);
        $volumeSchedule = $volume->scheduledBackups()->create(['team_id' => $this->teamB->id, 'frequency' => 'daily']);
        $execution = ScheduledVolumeBackupExecution::create([
            'scheduled_volume_backup_id' => $volumeSchedule->id,
            'status' => 'success',
        ]);
    }

    Livewire::test(BackupExecutions::class, ['service' => $this->ownService])
        ->assertViewHas('executions', fn ($executions) => $executions->isEmpty())
        ->assertDontSeeHtml('aria-label="Next page"')
        ->call('openExecution', $execution->uuid)
        ->assertNotFound();
})->with(['database', 'storage']);

test('execution page size remains adjustable when all runs fit on one page', function () {
    $schedule = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);
    foreach (range(1, 11) as $index) {
        $schedule->executions()->create(['status' => 'success']);
    }

    Livewire::test(BackupExecutions::class, ['service' => $this->ownService])
        ->set('perPage', 25)
        ->assertSeeHtml('aria-label="Items per page"')
        ->assertDontSeeHtml('aria-label="Next page"')
        ->set('perPage', 10)
        ->assertSeeHtml('aria-label="Next page"');
});

test('service backup lists identify the configured S3 storage without extra columns', function () {
    foreach (['Cloudflare R2', 'Railway S3', 'Maxio S3'] as $name) {
        $volume = LocalPersistentVolume::create([
            'name' => 'service-data-'.str($name)->slug(),
            'mount_path' => '/data',
            'resource_id' => $this->ownServiceDatabase->id,
            'resource_type' => $this->ownServiceDatabase->getMorphClass(),
        ]);
        $storage = S3Storage::create(['key' => 'key', 'secret' => 'secret', 'region' => 'auto', 'endpoint' => 'https://s3.example.com', 'team_id' => $this->teamA->id, 'name' => $name, 'bucket' => 'backups']);
        ScheduledDatabaseBackup::create([
            'team_id' => $this->teamA->id,
            'frequency' => 'daily',
            'database_id' => $this->ownServiceDatabase->id,
            'database_type' => $this->ownServiceDatabase->getMorphClass(),
            'save_s3' => true,
            's3_storage_id' => $storage->id,
        ]);
        $volume->scheduledBackups()->create([
            'team_id' => $this->teamA->id,
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_id' => $storage->id,
        ]);
    }

    $html = Livewire::test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])->html();
    foreach (['Cloudflare R2', 'Railway S3', 'Maxio S3'] as $name) {
        expect(substr_count($html, 'data-tooltip="S3 storage: '.$name.' (bucket: backups)"'))->toBe(2);
    }
});

test('execution tooltips distinguish current database storage from the recorded storage destination', function () {
    $original = S3Storage::create(['key' => 'key', 'secret' => 'secret', 'region' => 'auto', 'endpoint' => 'https://s3.example.com', 'team_id' => $this->teamA->id, 'name' => 'Cloudflare R2', 'bucket' => 'original']);
    $current = S3Storage::create(['key' => 'key', 'secret' => 'secret', 'region' => 'auto', 'endpoint' => 'https://s3.example.com', 'team_id' => $this->teamA->id, 'name' => 'Railway S3', 'bucket' => 'current']);
    $databaseSchedule = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
        'save_s3' => true,
        's3_storage_id' => $current->id,
    ]);
    $databaseSchedule->executions()->create(['status' => 'success', 's3_uploaded' => true]);
    $volume = LocalPersistentVolume::create([
        'name' => 'service-data',
        'mount_path' => '/data',
        'resource_id' => $this->ownServiceDatabase->id,
        'resource_type' => $this->ownServiceDatabase->getMorphClass(),
    ]);
    $volumeSchedule = $volume->scheduledBackups()->create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => $current->id,
    ]);
    $volumeSchedule->executions()->create(['status' => 'success', 's3_uploaded' => true, 's3_storage_id' => $original->id]);

    Livewire::test(BackupExecutions::class, ['service' => $this->ownService])
        ->assertSeeHtml('data-tooltip="Current schedule S3 storage: Railway S3 (bucket: current)"')
        ->assertSeeHtml('data-tooltip="S3 storage: Cloudflare R2 (bucket: original)"');

    $current->update(['team_id' => $this->teamB->id]);
    Livewire::test(ServiceVolumeBackupIndex::class, ['service' => $this->ownService])
        ->assertDontSee('Railway S3')
        ->assertSeeHtml('data-tooltip="S3 storage: Unavailable"');
    Livewire::test(BackupExecutions::class, ['service' => $this->ownService])
        ->assertDontSee('Railway S3')
        ->assertSeeHtml('data-tooltip="Current schedule S3 storage: Unavailable"');

    $databaseSchedule->update(['save_s3' => false]);
    $original->delete();
    Livewire::test(BackupExecutions::class, ['service' => $this->ownService])
        ->assertSeeHtml('data-tooltip="Current schedule S3 storage: Not configured"')
        ->assertDontSeeHtml('data-tooltip="S3 storage: Cloudflare R2 (bucket: original)"')
        ->assertSeeHtml('data-tooltip="S3 storage: Unavailable"');
});
