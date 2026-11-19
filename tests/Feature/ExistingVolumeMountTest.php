<?php

use App\Jobs\VolumeCloneJob;
use App\Livewire\Project\Service\Storage;
use App\Livewire\Project\Shared\Storages\All as AllStorages;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

it('loads unique sorted Docker volumes from the resource server', function () {
    Process::fake([
        '*' => Process::result(output: "zeta-volume\nalpha-volume\nzeta-volume\n"),
    ]);

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->call('loadExistingVolumes')
        ->assertSet('existingVolumes', ['alpha-volume', 'zeta-volume']);

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "docker volume ls --format '{{.Name}}'"));
});

it('attaches an existing volume without changing its Docker name', function () {
    Process::fake([
        '*' => Process::result(output: "shared-database-data\n"),
    ]);

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('existingVolumes', ['shared-database-data'])
        ->set('existing_volume', 'shared-database-data')
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

it('lets a preview deployment create its own volume when the preview suffix is enabled', function () {
    $storage = LocalPersistentVolume::create([
        'name' => 'shared-database-data',
        'mount_path' => '/var/lib/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_external' => true,
    ]);

    expect($storage->dockerComposeVolumeDefinition('shared-database-data-pr-7'))->toBe([
        'name' => 'shared-database-data-pr-7',
        'external' => false,
    ]);
});

it('does not delete an attached external volume with a standalone database', function () {
    $database = StandalonePostgresql::create([
        'name' => 'external-volume-db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app',
        'image' => 'postgres:17',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $database->persistentStorages()->delete();
    LocalPersistentVolume::create([
        'name' => 'shared-database-data',
        'mount_path' => '/var/lib/data',
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
        'is_external' => true,
    ]);
    Process::fake();

    $database->deleteVolumes();

    Process::assertNothingRan();
});

it('keeps the name of an external volume and copies no data when cloning an application', function () {
    LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_external' => true,
    ]);
    $this->application->settings->update(['is_container_label_readonly_enabled' => false]);
    Bus::fake();
    Process::fake();

    $clone = clone_application($this->application, $this->application->destination, cloneVolumeData: true);

    $clonedStorage = $clone->persistentStorages()->sole();
    expect($clonedStorage->name)->toBe('shared-app-data')
        ->and($clonedStorage->is_external)->toBeTrue();
    Bus::assertNotDispatched(VolumeCloneJob::class);
});

it('does not clone an application to a server that does not have its external volume', function () {
    LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_external' => true,
    ]);
    $targetServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->server->private_key_id,
    ]);
    $targetDestination = StandaloneDocker::factory()->create([
        'server_id' => $targetServer->id,
        'network' => 'clone-target',
    ]);
    Process::fake([
        '*' => Process::result(output: "__COOLIFY_VOLUME_ABSENT__\n"),
    ]);
    $applicationCount = Application::query()->count();

    expect(fn () => clone_application($this->application, $targetDestination))
        ->toThrow(RuntimeException::class, 'does not exist on the target server');
    expect(Application::query()->count())->toBe($applicationCount);
});

it('fails closed when the external volume cannot be checked on the target server', function () {
    $storage = LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_external' => true,
    ]);
    $targetServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->server->private_key_id,
    ]);
    Process::fake([
        '*' => Process::result(errorOutput: 'SSH connection failed', exitCode: 255),
    ]);

    expect(fn () => $storage->ensureCloneTargetIsAvailable($this->server, $targetServer))
        ->toThrow(RuntimeException::class);
});

it('does not rename or delete the Docker volume of an external storage', function () {
    $storage = LocalPersistentVolume::create([
        'name' => 'shared-app-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_external' => true,
    ]);
    Process::fake();

    $component = Livewire::test(AllStorages::class, ['resource' => $this->application])
        ->set("forms.{$storage->id}.name", 'other-volume')
        ->call('submit', $storage->id)
        ->call('submit', $storage->id, true);

    expect($storage->fresh()->name)->toBe('shared-app-data');

    $component->call('delete', $storage->id, 'password', ['deleteDockerVolume']);

    expect(LocalPersistentVolume::query()->find($storage->id))->toBeNull();
    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), 'docker volume rm'));
});
