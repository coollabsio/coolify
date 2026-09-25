<?php

use App\Livewire\Project\Database\Sqlite\ConnectApplication;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneSqlite;
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
        'private_key' => encrypt('test-key'),
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
        'ip' => '203.0.113.10',
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    $this->destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->sqlite = StandaloneSqlite::create([
        'name' => 'app-sqlite',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->application = createSqliteConnectApplication($this->environment, $this->destination, 'Nginx App');
});

function createSqliteConnectApplication(Environment $environment, StandaloneDocker $destination, string $name, string $buildPack = 'dockerimage'): Application
{
    return Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => $buildPack,
        'docker_registry_image_name' => 'nginx',
    ]);
}

it('mounts the data volume into the selected application and redirects to its storage page', function () {
    Livewire::test(ConnectApplication::class, ['database' => $this->sqlite])
        ->set('applicationUuid', $this->application->uuid)
        ->set('mountPath', '/app/database')
        ->call('connect')
        ->assertHasNoErrors()
        ->assertRedirect(route('project.application.persistent-storage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ]));

    $volume = $this->application->persistentStorages()->sole();

    expect($volume->name)->toBe('sqlite-data-'.$this->sqlite->uuid)
        ->and($volume->mount_path)->toBe('/app/database')
        ->and($volume->is_preview_suffix_enabled)->toBeFalse();
});

it('refuses to mount the volume into compose applications', function () {
    $compose = createSqliteConnectApplication($this->environment, $this->destination, 'Compose App', 'dockercompose');

    Livewire::test(ConnectApplication::class, ['database' => $this->sqlite])
        ->assertSet('applicationOptions', fn (array $options) => collect($options)->firstWhere('value', $compose->uuid)['disabled'] === true)
        ->set('applicationUuid', $compose->uuid)
        ->call('connect')
        ->assertDispatched('error')
        ->assertNoRedirect();

    expect($compose->persistentStorages()->count())->toBe(0);
});

it('ignores applications that belong to another team', function () {
    $otherProject = Project::factory()->create(['team_id' => Team::factory()->create()->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = createSqliteConnectApplication($otherEnvironment, $this->destination, 'Other Team App');

    Livewire::test(ConnectApplication::class, ['database' => $this->sqlite])
        ->assertSet('applicationOptions', fn (array $options) => collect($options)->pluck('value')->all() === [$this->application->uuid])
        ->set('applicationUuid', $otherApplication->uuid)
        ->call('connect')
        ->assertDispatched('error')
        ->assertNoRedirect();

    expect($otherApplication->persistentStorages()->count())->toBe(0);
});

it('forbids team members from mounting the volume into an application', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::actingAs($member)
        ->test(ConnectApplication::class, ['database' => $this->sqlite])
        ->set('applicationUuid', $this->application->uuid)
        ->call('connect')
        ->assertForbidden();

    expect($this->application->persistentStorages()->count())->toBe(0);
});

it('keeps a docker volume shared with another resource when the application volumes are deleted', function () {
    Process::fake();

    LocalPersistentVolume::create([
        'name' => 'sqlite-data-'.$this->sqlite->uuid,
        'mount_path' => StandaloneSqlite::DATA_DIRECTORY,
        'host_path' => null,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_preview_suffix_enabled' => false,
    ]);
    LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/data',
        'host_path' => null,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $this->application->deleteVolumes();

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker volume rm -f '.escapeshellarg($this->application->uuid.'-data')));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'sqlite-data-'.$this->sqlite->uuid));
});
