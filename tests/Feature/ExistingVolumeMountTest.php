<?php

use App\Jobs\VolumeCloneJob;
use App\Livewire\Project\Service\Storage;
use App\Livewire\Project\Shared\Storages\Show as ShowStorage;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'existing-volume-test',
    ]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('shows the searchable existing volume field and data corruption warning', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->assertSee('Existing Volume')
        ->assertSee('Attaching an existing volume that is already used by another container can cause data corruption.');
});

it('loads unique sorted Docker volumes from the resource server', function () {
    Process::fake([
        '*' => Process::result(output: "zeta-volume\nalpha-volume\nzeta-volume\n"),
    ]);

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->call('loadExistingVolumes')
        ->assertSet('existingVolumes', ['alpha-volume', 'zeta-volume']);

    Process::assertRan(fn ($process) => str_contains($process->command, "docker volume ls --format '{{.Name}}'"));
});

it('attaches an existing volume without changing its Docker name', function () {
    Process::fake([
        '*' => Process::result(output: "shared-database-data\n"),
    ]);

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('existingVolumes', ['shared-database-data'])
        ->set('existing_volume', 'shared-database-data')
        ->set('isSwarm', true)
        ->set('host_path', '/previous/source/path')
        ->set('mount_path', '/var/lib/data')
        ->call('submitPersistentVolume')
        ->assertDispatched('success');

    $storage = $this->application->persistentStorages()->sole();

    expect($storage->name)->toBe('shared-database-data')
        ->and($storage->mount_path)->toBe('/var/lib/data')
        ->and($storage->host_path)->toBeNull()
        ->and($storage->is_external)->toBeTrue()
        ->and($storage->is_preview_suffix_enabled)->toBeFalse();
});

it('rejects an existing volume that was not returned by the server', function () {
    Process::fake([
        '*' => Process::result(output: "allowed-volume\n"),
    ]);

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('existingVolumes', ['tampered-volume'])
        ->set('existing_volume', 'tampered-volume')
        ->set('mount_path', '/var/lib/data')
        ->call('submitPersistentVolume')
        ->assertDispatched('error');

    expect($this->application->persistentStorages()->count())->toBe(0);
});

it('declares attached existing volumes as external in Docker Compose', function () {
    $storage = LocalPersistentVolume::create([
        'name' => 'shared-database-data',
        'mount_path' => '/var/lib/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_external' => true,
    ]);

    expect($storage->dockerComposeVolumeDefinition())->toBe([
        'name' => 'shared-database-data',
        'external' => true,
    ]);
});

it('does not delete attached external volumes with the application', function () {
    LocalPersistentVolume::create([
        'name' => 'shared-database-data',
        'mount_path' => '/var/lib/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_external' => true,
    ]);
    Process::fake();

    $this->application->deleteVolumes();

    Process::assertNothingRan();
});

it('allows a Docker Compose volume to keep its declared name', function () {
    $this->application->update(['build_pack' => 'dockercompose']);
    $storage = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'_app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ])->load('resource');

    Livewire::test(ShowStorage::class, [
        'storage' => $storage,
        'resource' => $this->application,
    ])
        ->assertSee('Use volume name as-is')
        ->set('isNameAsIs', true)
        ->call('instantSave')
        ->assertDispatched('success');

    expect($storage->fresh()->is_name_as_is)->toBeTrue();
});

it('does not automatically delete name-as-is volumes', function () {
    $this->application->update(['build_pack' => 'dockercompose']);
    LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_name_as_is' => true,
    ]);
    Process::fake();

    $this->application->deleteVolumes();

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker compose down')
        && ! str_contains($process->command, 'docker compose down -v'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker volume rm'));
});

it('copies name-as-is volume data only when cloning to another server', function () {
    $storage = LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_name_as_is' => true,
    ]);
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);

    expect($storage->shouldCopyDataWhenCloning(true, $this->server, $this->server))->toBeFalse()
        ->and($storage->shouldCopyDataWhenCloning(true, $this->server, $otherServer))->toBeTrue()
        ->and($storage->shouldCopyDataWhenCloning(false, $this->server, $otherServer))->toBeFalse();
});

it('refuses to overwrite an existing name-as-is volume during a cross-server clone', function () {
    $storage = LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_name_as_is' => true,
    ]);
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    Process::fake([
        '*' => Process::result(output: "__COOLIFY_VOLUME_PRESENT__\n"),
    ]);

    $job = new VolumeCloneJob('shared-app-data', 'shared-app-data', $this->server, $otherServer, $storage);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'already exists');
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker volume ls'));
});

it('does not delete a name-as-is volume when deleting a Docker Compose preview', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - app-data:/app/data
volumes:
  app-data:
    name: shared-app-data
YAML,
    ]);
    LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'container_id' => 'app:app-data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_name_as_is' => true,
    ]);
    $preview = ApplicationPreview::create([
        'uuid' => 'preview-with-shared-volume',
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://example.com/pull/42',
        'status' => 'exited',
    ]);
    Process::fake();

    $preview->forceDelete();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker volume rm -f')
        && str_contains($process->command, 'shared-app-data'));
});

it('rejects tampering that enables name-as-is for unsupported resources', function () {
    $storage = LocalPersistentVolume::create([
        'name' => 'application-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ])->load('resource');

    Livewire::test(ShowStorage::class, [
        'storage' => $storage,
        'resource' => $this->application,
    ])
        ->set('canUseNameAsIs', true)
        ->set('isNameAsIs', true)
        ->call('instantSave');

    expect($storage->fresh()->is_name_as_is)->toBeFalse();
});

it('does not persist an application clone when its name-as-is target volume already exists', function () {
    LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_name_as_is' => true,
    ]);
    $targetServer = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $targetDestination = StandaloneDocker::factory()->create([
        'server_id' => $targetServer->id,
        'network' => 'clone-target',
    ]);
    Process::fake([
        '*' => Process::result(output: "__COOLIFY_VOLUME_PRESENT__\n"),
    ]);
    $this->application->settings->update(['is_container_label_readonly_enabled' => false]);
    $applicationCount = Application::query()->count();

    expect(fn () => clone_application($this->application, $targetDestination, cloneVolumeData: true))
        ->toThrow(RuntimeException::class, 'already exists');
    expect(Application::query()->count())->toBe($applicationCount);
});

it('fails closed when target volume availability cannot be checked', function () {
    $storage = LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_name_as_is' => true,
    ]);
    $targetServer = Server::factory()->create(['team_id' => $this->team->id]);
    Process::fake([
        '*' => Process::result(errorOutput: 'SSH connection failed', exitCode: 255),
    ]);

    expect(fn () => $storage->ensureCloneTargetIsAvailable(true, $this->server, $targetServer))
        ->toThrow(RuntimeException::class);
});
