<?php

use App\Jobs\ServerStorageSaveJob;
use App\Livewire\Project\Service\Storage;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
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
        ->assertSeeText('This file will be created on the host, then mounted into the container.')
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
        ->assertDispatched('success');

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
        ->assertDispatched('success');

    $volume = LocalFileVolume::query()->sole();

    expect($volume->fs_path)->toBe('/etc/nginx/nginx.conf')
        ->and($volume->mount_path)->toBe('/etc/nginx/nginx.conf')
        ->and($volume->content)->toBeNull()
        ->and($volume->is_host_file)->toBeTrue()
        ->and($volume->is_directory)->toBeFalse();

    Bus::assertNotDispatched(ServerStorageSaveJob::class);
});
