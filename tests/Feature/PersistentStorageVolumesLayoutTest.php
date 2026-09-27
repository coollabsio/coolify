<?php

use App\Livewire\Project\Service\Storage;
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

it('keeps nested storage component keys stable when mounts are added or deleted', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/storage.blade.php'));

    expect($view)
        ->toContain('wire:key="volumes-{{ $resource->id }}"')
        ->toContain('wire:key="svc-volumes-{{ $resource->id }}"')
        ->not->toContain('wire:key="volumes-{{ $resource->id }}-{{ $this->volumeCount }}"')
        ->not->toContain('wire:key="svc-volumes-{{ $resource->id }}-{{ $this->volumeCount }}"');
});

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

it('creates named Docker volumes without a source path in swarm mode', function () {
    [$application] = createApplicationWithVolume();
    $application->persistentStorages()->delete();

    Livewire::test(Storage::class, ['resource' => $application])
        ->set('isSwarm', true)
        ->set('name', 'storage-app-data')
        ->set('mount_path', '/data')
        ->call('submitPersistentVolume')
        ->assertHasNoErrors();

    expect($application->persistentStorages()->first()->host_path)->toBeNull();
});

it('declares explicit authorization on the changed storage controls', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/storages/all.blade.php'));

    preg_match(
        '/<x-forms\.listbox\s+id="forms\.\{\{ \$id \}\}\.isPreviewSuffixEnabled"[\s\S]*?\/>/',
        $view,
        $previewSuffixListbox
    );

    expect($previewSuffixListbox[0] ?? '')
        ->toContain('canGate="update"')
        ->toContain(':canResource="$resource"');

    preg_match_all('/<x-forms\.button\b[^>]*>\s*Backup\s*<\/x-forms\.button>/s', $view, $backupButtons);

    expect($backupButtons[0])->toHaveCount(3);

    foreach ($backupButtons[0] as $backupButton) {
        expect($backupButton)
            ->toContain('canGate="update"')
            ->toContain(':canResource="$resource"');
    }
});

it('creates named volumes without a host path in swarm mode', function () {
    [$application] = createApplicationWithVolume();
    $application->persistentStorages()->delete();

    Livewire::test(Storage::class, ['resource' => $application])
        ->set('isSwarm', true)
        ->set('name', 'storage-app-data')
        ->set('mount_path', '/data')
        ->call('submitPersistentVolume')
        ->assertHasNoErrors();

    expect($application->persistentStorages()->first())
        ->name->toBe($application->uuid.'-storage-app-data')
        ->host_path->toBeNull();
});

it('uses a resource based default name for new volumes', function () {
    [$application] = createApplicationWithVolume(['name' => 'Storage App']);

    Livewire::test(Storage::class, ['resource' => $application])
        ->assertSet('name', 'storage-app-data');
});

it('uses a valid fallback default volume name when the resource name has no slug characters', function () {
    [$application] = createApplicationWithVolume(['name' => '---']);

    Livewire::test(Storage::class, ['resource' => $application])
        ->assertSet('name', 'volume-data');
});

it('preserves existing bind mount source paths in the volume table', function () {
    [$application, $volume] = createApplicationWithVolume(volumeAttributes: [
        'host_path' => '/srv/storage',
    ]);

    Livewire::test(All::class, ['resource' => $application])
        ->assertDontSee('Directory mount')
        ->assertSee('/srv/storage')
        ->assertDontSee('Remove Source Path');

    expect($volume->refresh()->host_path)->toBe('/srv/storage')
        ->and(method_exists(All::class, 'clearHostPath'))->toBeFalse();
});

it('does not show a source path or removal action for a named volume', function () {
    [$application] = createApplicationWithVolume();

    Livewire::test(All::class, ['resource' => $application])
        ->assertDontSee('Directory mount')
        ->assertSee('Volume mount')
        ->assertDontSee('Remove Source Path');
});

it('keeps bind mount source paths out of editable form state', function () {
    [$application, $volume] = createApplicationWithVolume(volumeAttributes: ['host_path' => '/srv/storage']);

    $component = Livewire::test(All::class, ['resource' => $application]);

    expect($component->get('forms')[$volume->id])->not->toHaveKey('hostPath');

    $component
        ->call('submit', $volume->id)
        ->assertHasNoErrors();

    expect($volume->refresh()->host_path)->toBe('/srv/storage');
});

it('does not offer bind mount conversion to a team member', function () {
    [$application, $volume] = createApplicationWithVolume(volumeAttributes: ['host_path' => '/srv/storage']);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(All::class, ['resource' => $application])
        ->assertDontSee('Directory mount')
        ->assertSee('/srv/storage')
        ->assertDontSee('Remove Source Path');

    expect($volume->refresh()->host_path)->toBe('/srv/storage');
});

it('does not show a bind mount source path to another team', function () {
    [$application, $volume] = createApplicationWithVolume(volumeAttributes: ['host_path' => '/srv/storage']);
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(All::class, ['resource' => $application])
        ->assertForbidden();

    expect($volume->refresh()->host_path)->toBe('/srv/storage');
});

it('labels bind mounts as directories in deployment configuration', function () {
    [$application, $volume] = createApplicationWithVolume(volumeAttributes: ['host_path' => '/srv/storage']);

    $storage = collect(data_get($application->deploymentConfigurationSnapshot(), 'sections.storage.items'));

    expect($storage->firstWhere('key', 'volume_'.$volume->id))
        ->toMatchArray(['label' => 'Directory mount', 'display_value' => '/srv/storage → /data']);

    $volume->update(['host_path' => null]);
    $storage = collect(data_get($application->deploymentConfigurationSnapshot(), 'sections.storage.items'));

    expect($storage->firstWhere('key', 'volume_'.$volume->id)['label'])->toBe('Volume mount');
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
