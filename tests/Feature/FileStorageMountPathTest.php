<?php

use App\Jobs\ServerStorageSaveJob;
use App\Livewire\Project\Service\FileStorage;
use App\Livewire\Project\Service\Storage;
use App\Livewire\Project\Shared\Storages\All;
use App\Livewire\Project\Shared\Storages\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Bus::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

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
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

test('livewire file storage rejects parent segments and does not create a local file volume', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_path', '/../../../../../../etc/example.conf')
        ->set('file_storage_content', 'owned')
        ->call('submitFileStorage')
        ->assertDispatched('error');

    expect(LocalFileVolume::query()->count())->toBe(0);
});

test('file mount modal shows the calculated host file path above the destination input', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->assertSeeText('Create a managed file on the host and mount it inside the container.')
        ->assertSeeText('Host file path')
        ->assertSeeText($this->application->workdir().'/')
        ->set('file_storage_path', '/etc/nginx/nginx.conf')
        ->assertSeeText($this->application->workdir().'/etc/nginx/nginx.conf')
        ->assertDontSeeText('Actual file mounted from the host system to the container.');
});

test('livewire file storage stores safe file mounts under the application configuration root', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_path', '/etc/nginx/nginx.conf')
        ->set('file_storage_content', 'server {}')
        ->call('submitFileStorage')
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    $volume = LocalFileVolume::query()->sole();

    expect($volume->mount_path)->toBe('/etc/nginx/nginx.conf')
        ->and($volume->fs_path)->toBe(application_configuration_dir().'/'.$this->application->uuid.'/etc/nginx/nginx.conf')
        ->and($volume->is_directory)->toBeFalse();
});

test('livewire host file storage stores an existing host file path without managed content', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('host_file_storage_source', '/etc/nginx/nginx.conf')
        ->set('host_file_storage_destination', '/etc/nginx/nginx.conf')
        ->call('submitHostFileStorage')
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    $volume = LocalFileVolume::query()->sole();

    expect($volume->fs_path)->toBe('/etc/nginx/nginx.conf')
        ->and($volume->mount_path)->toBe('/etc/nginx/nginx.conf')
        ->and($volume->content)->toBeNull()
        ->and($volume->is_host_file)->toBeTrue()
        ->and($volume->is_directory)->toBeFalse();

    Bus::assertNotDispatched(ServerStorageSaveJob::class);
});

test('livewire volume storage refreshes the storage list and configuration warning', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('name', 'data')
        ->set('mount_path', '/app/data')
        ->call('submitPersistentVolume')
        ->assertDispatched('success')
        ->assertDispatched('refreshStorages')
        ->assertDispatched('configurationChanged')
        ->assertSet('activeTab', 'volumes')
        ->assertSet('volumeCount', 1)
        ->assertSee($this->application->uuid.'-data');
});

test('volume storage list shows volumes added after it was mounted', function () {
    $firstVolume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-first',
        'mount_path' => '/app/first',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $storageList = Livewire::test(All::class, ['resource' => $this->application])
        ->assertSee($firstVolume->name);

    $secondVolume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-second',
        'mount_path' => '/app/second',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $storageList
        ->assertDontSee($secondVolume->name)
        ->call('refreshList')
        ->assertSee($secondVolume->name);
});

test('adding a volume switches to the volumes tab immediately', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->assertSet('activeTab', 'volumes')
        ->set('activeTab', 'directories')
        ->set('name', 'cache')
        ->set('mount_path', '/cache')
        ->call('submitPersistentVolume')
        ->assertSet('activeTab', 'volumes')
        ->assertSee($this->application->uuid.'-cache')
        ->assertDontSee('No directory mounts configured');
});

test('deleting a file mount refreshes the configuration warning', function () {
    $file = LocalFileVolume::create([
        'fs_path' => '/etc/nginx/nginx.conf',
        'mount_path' => '/etc/nginx/nginx.conf',
        'is_host_file' => true,
        'is_based_on_git' => false,
        'is_preview_suffix_enabled' => true,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    Livewire::test(FileStorage::class, ['fileStorage' => $file])
        ->call('delete', 'password')
        ->assertDispatched('configurationChanged');

    expect($file->fresh())->toBeNull();
});

test('deleting a volume mount refreshes the configuration warning', function () {
    $database = StandalonePostgresql::create([
        'name' => 'test-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => $database->uuid.'-data',
        'mount_path' => '/var/lib/postgresql/data',
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);

    Livewire::test(Show::class, ['storage' => $volume, 'resource' => $database])
        ->call('delete', 'password')
        ->assertDispatched('configurationChanged');

    expect($volume->fresh())->toBeNull();
});
