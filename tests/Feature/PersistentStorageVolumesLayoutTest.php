<?php

it('keeps the volume backup executions table horizontally scrollable on mobile', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/storages/volume-backups/executions.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('volume-backup-executions-grid')
        ->toContain('data-table w-full overflow-x-auto')
        ->and($css)
        ->toContain('.volume-backup-executions-grid')
        ->toContain('min-width: 50rem;')
        ->not->toContain('.data-table-header.volume-backup-executions-grid');
});

it('uses compact icon actions for volume backup executions', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/storages/volume-backups/executions.blade.php'));

    expect($view)
        ->toContain('title="Download backup" aria-label="Download backup"')
        ->toContain('<x-reicon name="upload" class="size-3.5 rotate-180" />')
        ->toContain('title="Delete backup" aria-label="Delete backup"')
        ->toContain('<x-reicon name="trash" class="size-3.5" />');
});

it('keeps storage backup schedule tables horizontally scrollable on mobile', function () {
    $applicationView = file_get_contents(resource_path('views/livewire/project/application/backup/index.blade.php'));
    $serviceView = file_get_contents(resource_path('views/livewire/project/service/volume-backup/index.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($applicationView)->toContain('class="data-table w-full overflow-x-auto"')
        ->and($serviceView)->toContain('class="data-table w-full overflow-x-auto"')
        ->and($css)->toMatch('/\.backup-table-grid\s*\{[^}]*min-width:\s*50rem;/');
});

use App\Livewire\Project\Service\VolumeBackup\Create as CreateServiceVolumeBackup;
use App\Livewire\Project\Shared\Storages\All;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false]
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
        'ip' => '203.0.113.10',
    ]);

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function createApplicationWithVolume(array $applicationAttributes = [], array $volumeAttributes = []): array
{
    $application = Application::factory()->create(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Storage App',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
    ], $applicationAttributes));

    $volume = LocalPersistentVolume::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => $application->uuid.'-data',
        'mount_path' => '/data',
        'host_path' => null,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
        'is_preview_suffix_enabled' => true,
    ], $volumeAttributes));

    return [$application, $volume];
}

it('renders volumes as a data table with shared column headers', function () {
    $allView = file_get_contents(resource_path('views/livewire/project/shared/storages/all.blade.php'));
    $showView = file_get_contents(resource_path('views/livewire/project/shared/storages/show.blade.php'));
    $storageView = file_get_contents(resource_path('views/livewire/project/service/storage.blade.php'));

    expect($allView)
        ->toContain('data-table')
        ->toContain('data-table-header')
        ->toContain('volumes-table-grid')
        ->toContain('volumes-table-grid-readonly')
        ->toContain('Volume Name')
        ->toContain('Source Path')
        ->toContain('Destination Path')
        ->toContain('volumes-col-backup')
        ->toContain('supportsPreviewSuffix')
        ->toContain('x-modal-input')
        ->not->toContain('wire:click="openBackupModal')
        ->toContain('data-table-row')
        ->toContain('volumes-mobile-label')
        ->not->toContain('table-badge table-badge-success')
        ->not->toContain('livewire:project.shared.storages.show')
        ->not->toContain('x-status-badge')
        ->not->toContain('font-mono')
        ->not->toContain('Service volume mounts are read-only here.');

    // Show remains available for isolated embeds/tests but is no longer nested from All.
    expect($showView)
        ->toContain('data-table-row')
        ->toContain('volumes-table-grid')
        ->not->toContain('font-mono');

    // Service stack page: one settings-section card per compose service/resource.
    expect($storageView)
        ->toContain('Str::headline($resource->name)')
        ->toContain(':flush="true"')
        ->toContain("'storage-service-'.\$resource->uuid");

    $serviceConfigurationView = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));

    expect($serviceConfigurationView)
        ->toContain('storageSections')
        ->toContain('menu-subitem')
        ->toContain('scrollToSettingsSection')
        ->toContain('Service volume mounts are read-only here.')
        ->toContain("'storage-service-'.\$resource->uuid");

    expect($serviceConfigurationView)->not->toContain('Storage Backups');
    expect(file_get_contents(resource_path('views/livewire/project/service/heading.blade.php')))
        ->toContain("'label' => 'Backups'")
        ->toContain('project.service.volume-backups.*');
    expect(file_get_contents(resource_path('views/livewire/project/service/volume-backup/show.blade.php')))
        ->toContain('context="service-volume"');
    expect(file_get_contents(resource_path('views/livewire/project/shared/storages/volume-backups/general.blade.php')))
        ->not->toContain('<code')
        ->toMatch('/<x-callout[^>]*title="File-level consistency"[\s\S]*id="stopDuringBackup"[\s\S]*<\/x-callout>/');
    expect(file_get_contents(resource_path('views/livewire/project/shared/storages/volume-backups/executions.blade.php')))
        ->toContain('<span>Time</span>')
        ->toContain('x-forms.copy-button')
        ->toContain('col-span-6');

    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.volumes-table-grid')
        ->toContain('.volumes-table-grid-with-pr')
        ->toContain('.volumes-table-grid-readonly')
        ->toContain('.volumes-mobile-label')
        ->toContain('font-size: 13px') // same as .application-settings-form label
        ->toContain('@media (max-width: 768px)')
        ->toContain('.table-badge-success');

    expect($css)
        ->toContain('12rem')
        ->not->toContain('17.5rem');

    // Settings form labels are 13px (not Tailwind text-sm 14px).
    expect($css)
        ->toMatch('/\.application-settings-form label\s*\{[^}]*font-size:\s*13px/s');
});

it('creates and exposes volume backups for service storage', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $serviceApplication = ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'buzz',
    ]);
    $volume = LocalPersistentVolume::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'buzz-data',
        'mount_path' => '/data',
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ]);

    Livewire::test(CreateServiceVolumeBackup::class, [
        'service' => $service,
        'selectedTargetKey' => 'volume:'.$volume->id,
    ])->set('frequency', 'daily')
        ->call('submit')
        ->assertHasNoErrors();

    expect($volume->scheduledBackups()->first())
        ->not->toBeNull()
        ->enabled->toBeTrue();
    expect(ScheduledVolumeBackup::query()->forService($service)->count())->toBe(1);

    Livewire::test(All::class, ['resource' => $serviceApplication])
        ->assertSet('showActionsColumn', true)
        ->assertSee('Backup')
        ->assertSeeHtml('title="Volume backup is enabled"');
});

it('shows PR deployment suffix only for git-based applications', function () {
    [$gitApp] = createApplicationWithVolume(['build_pack' => 'nixpacks']);

    Livewire::test(All::class, ['resource' => $gitApp])
        ->assertSet('supportsPreviewSuffix', true)
        ->assertSee('Add suffix');

    [$dockerImageApp] = createApplicationWithVolume([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'latest',
    ]);

    Livewire::test(All::class, ['resource' => $dockerImageApp])
        ->assertSet('supportsPreviewSuffix', false)
        ->assertDontSee('Add suffix')
        ->assertDontSee('PR deployment suffix');

    [$nonGitComposeApp] = createApplicationWithVolume([
        'build_pack' => 'dockercompose',
        'git_repository' => '',
        'git_branch' => '',
    ]);

    Livewire::test(All::class, ['resource' => $nonGitComposeApp])
        ->assertSet('supportsPreviewSuffix', false)
        ->assertDontSee('Add suffix');
});

it('allows stale compose volume metadata to be deleted', function () {
    [$application, $volume] = createApplicationWithVolume([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
YAML,
    ]);

    Livewire::test(All::class, ['resource' => $application])
        ->assertSee('Delete stale volume entry')
        ->call('delete', $volume->id, 'password');

    expect($volume->fresh())->toBeNull();
});

it('deletes the Docker volume only when explicitly selected', function () {
    Process::fake();
    DB::table('private_keys')->where('id', $this->server->private_key_id)->update([
        'private_key' => encrypt('test-key'),
    ]);

    [$application, $volume] = createApplicationWithVolume([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
YAML,
    ]);

    Livewire::test(All::class, ['resource' => $application])
        ->assertSet('deleteDockerVolume', false)
        ->call('delete', $volume->id, 'password', ['deleteDockerVolume'])
        ->assertSet('deleteDockerVolume', true);

    Process::assertRan(fn () => true);
    expect($volume->fresh())->toBeNull();
});

it('does not allow compose volume metadata that is still declared to be deleted', function () {
    [$application, $volume] = createApplicationWithVolume([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ]);

    $volume->name = $application->uuid.'_data';
    $volume->save();

    Livewire::test(All::class, ['resource' => $application])
        ->assertDontSee('Delete stale volume entry')
        ->call('delete', $volume->id, 'password')
        ->assertDispatched('error');

    expect($volume->fresh())->not->toBeNull();
});

it('hides PR deployment suffix for databases', function () {
    $database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'pg-test',
        'postgres_password' => 'secret',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    LocalPersistentVolume::create([
        'uuid' => (string) Str::uuid(),
        'name' => $database->uuid.'-data',
        'mount_path' => '/var/lib/postgresql/data',
        'host_path' => null,
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
        'is_preview_suffix_enabled' => true,
    ]);

    Livewire::test(All::class, ['resource' => $database])
        ->assertSet('supportsPreviewSuffix', false)
        ->assertDontSee('Add suffix')
        ->assertDontSee('PR deployment suffix');
});

it('uses a compact table badge for enabled backups instead of status-badge', function () {
    $showView = file_get_contents(resource_path('views/livewire/project/shared/storages/show.blade.php'));

    expect($showView)
        ->toContain('table-badge-success')
        ->toContain('Volume backup is enabled')
        ->not->toContain('x-status-badge')
        ->not->toContain('status="Backup enabled"');

    // Badge label is the short "Backup" text, not the old pill-with-label that broke the input row.
    expect(preg_match('/table-badge-success[^>]*>\s*Backup\s*</', $showView))->toBeGreaterThan(0);
});

it('gates file storage PR suffix markup behind git_based applications', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/file-storage.blade.php'));

    expect($view)
        ->toContain('$resource->git_based()')
        ->toContain('PR deployment suffix');
});
