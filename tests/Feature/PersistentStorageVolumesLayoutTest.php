<?php

use App\Livewire\Project\Shared\Storages\All;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        ->toContain('supportsPreviewSuffix')
        ->toContain('openBackupModal')
        ->toContain('data-table-row')
        ->toContain('volumes-mobile-label')
        ->toContain('table-badge-success')
        ->not->toContain('livewire:project.shared.storages.show')
        ->not->toContain('x-status-badge');

    // Show remains available for isolated embeds/tests but is no longer nested from All.
    expect($showView)
        ->toContain('data-table-row')
        ->toContain('volumes-table-grid');

    // Service stack page: one settings-section card per compose service/resource.
    expect($storageView)
        ->toContain('Str::headline($resource->name)')
        ->toContain(':flush="true"')
        ->toContain('storage-service-');

    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.volumes-table-grid')
        ->toContain('.volumes-table-grid-with-pr')
        ->toContain('.volumes-table-grid-readonly')
        ->toContain('.volumes-mobile-label')
        ->toContain('font-size: 13px') // same as .application-settings-form label
        ->toContain('@media (max-width: 768px)')
        ->toContain('.table-badge-success');

    // Settings form labels are 13px (not Tailwind text-sm 14px).
    expect($css)
        ->toMatch('/\.application-settings-form label\s*\{[^}]*font-size:\s*13px/s');
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
