<?php

use App\Livewire\Project\Database\Sqlite\ConnectApplication;
use App\Livewire\Project\Shared\Danger;
use App\Livewire\Project\Shared\Storages\All;
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
use Illuminate\Support\Facades\Queue;
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

function createSqliteConnectApplication(Environment $environment, StandaloneDocker $destination, string $name, string $buildPack = 'dockerimage', ?string $compose = null): Application
{
    return Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => $buildPack,
        'docker_registry_image_name' => 'nginx',
        'docker_compose_raw' => $compose,
    ]);
}

function sqliteVolumeCompose(StandaloneSqlite $sqlite, string $declaration = 'external: true'): string
{
    $volume = 'sqlite-data-'.$sqlite->uuid;

    return "services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - '{$volume}:/app/database'\nvolumes:\n  {$volume}:\n    {$declaration}\n";
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
        ->and($volume->is_preview_suffix_enabled)->toBeFalse()
        ->and($volume->standalone_sqlite_id)->toBe($this->sqlite->id)
        ->and($this->sqlite->connectedVolumes()->sole()->resource->is($this->application))->toBeTrue();
});

it('shows the compose lines for compose applications instead of adding a storage entry', function () {
    $compose = createSqliteConnectApplication($this->environment, $this->destination, 'Compose App', 'dockercompose', "services:\n  api:\n    image: nginx:alpine\n");
    $volume = 'sqlite-data-'.$this->sqlite->uuid;

    $component = Livewire::test(ConnectApplication::class, ['database' => $this->sqlite])
        ->assertSet('applicationOptions', fn (array $options) => str_ends_with(collect($options)->firstWhere('value', $compose->uuid)['label'], '· Docker Compose'))
        ->set('applicationUuid', $compose->uuid)
        ->set('mountPath', '/app/database')
        ->call('connect')
        ->assertHasNoErrors()
        ->assertNotDispatched('error')
        ->assertNoRedirect()
        ->assertSet('composeApplicationName', 'Compose App')
        ->assertSet('composeSnippet', "services:\n  api:\n    volumes:\n      - '{$volume}:/app/database'\nvolumes:\n  {$volume}:\n    external: true\n")
        ->assertSee('Add the volume to the compose file of Compose App');

    validateDockerComposeForInjection($component->get('composeSnippet'));
    expect(composeExternalVolumeDockerNames($component->get('composeSnippet')))->toBe([$volume])
        ->and($compose->persistentStorages()->count())->toBe(0);
});

it('lists compose applications that declare the volume and blocks deleting it or the database', function () {
    createSqliteConnectApplication($this->environment, $this->destination, 'Compose App', 'dockercompose', sqliteVolumeCompose($this->sqlite));
    $databaseVolume = $this->sqlite->persistentStorages()->sole();

    expect($this->sqlite->hasConnectedApplications())->toBeTrue()
        ->and($this->sqlite->connectedApplicationNames()->all())->toBe(['Compose App'])
        ->and($databaseVolume->isSharedWithAnotherResource())->toBeTrue();

    Livewire::test(ConnectApplication::class, ['database' => $this->sqlite])
        ->assertSee('Remove it from the compose file to unlink');

    Livewire::test(All::class, ['resource' => $this->sqlite])
        ->call('delete', $databaseVolume->id, 'password')
        ->assertDispatched('error');

    Livewire::test(Danger::class, ['resource' => $this->sqlite])
        ->call('delete', 'password')
        ->assertDispatched('error');

    expect($databaseVolume->fresh())->not->toBeNull()
        ->and(StandaloneSqlite::find($this->sqlite->id))->not->toBeNull();
});

it('does not show the compose lines to a compose application that already declares the volume', function () {
    $compose = createSqliteConnectApplication($this->environment, $this->destination, 'Compose App', 'dockercompose', sqliteVolumeCompose($this->sqlite));

    Livewire::test(ConnectApplication::class, ['database' => $this->sqlite])
        ->set('applicationUuid', $compose->uuid)
        ->call('connect')
        ->assertDispatched('error')
        ->assertSet('composeSnippet', null);
});

it('counts only compose applications of the same team and server that declare the volume as external', function () {
    createSqliteConnectApplication($this->environment, $this->destination, 'Not External', 'dockercompose', sqliteVolumeCompose($this->sqlite, 'driver: local'));
    createSqliteConnectApplication($this->environment, $this->destination, 'Other Volume', 'dockercompose', "services:\n  web:\n    image: nginx\n    volumes:\n      - 'other:/data'\nvolumes:\n  other:\n    external: true\n");

    $otherServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->server->private_key_id,
        'ip' => '203.0.113.11',
    ]);
    $otherDestination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $otherServer->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'other-docker']
    ));
    createSqliteConnectApplication($this->environment, $otherDestination, 'Other Server', 'dockercompose', sqliteVolumeCompose($this->sqlite));

    $otherProject = Project::factory()->create(['team_id' => Team::factory()->create()->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    createSqliteConnectApplication($otherEnvironment, $this->destination, 'Other Team', 'dockercompose', sqliteVolumeCompose($this->sqlite));

    expect($this->sqlite->hasConnectedApplications())->toBeFalse()
        ->and($this->sqlite->connectedApplicationNames()->all())->toBe([])
        ->and($this->sqlite->persistentStorages()->sole()->isSharedWithAnotherResource())->toBeFalse();
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
        'standalone_sqlite_id' => $this->sqlite->id,
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

function connectSqliteVolume(Application $application, StandaloneSqlite $sqlite): LocalPersistentVolume
{
    return LocalPersistentVolume::create([
        'name' => 'sqlite-data-'.$sqlite->uuid,
        'mount_path' => StandaloneSqlite::DATA_DIRECTORY,
        'host_path' => null,
        'standalone_sqlite_id' => $sqlite->id,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
        'is_preview_suffix_enabled' => false,
    ]);
}

it('unlinks an application without touching the docker volume', function () {
    Process::fake();
    $volume = connectSqliteVolume($this->application, $this->sqlite);

    Livewire::test(ConnectApplication::class, ['database' => $this->sqlite])
        ->call('unlink', $volume->id)
        ->assertDispatched('success');

    Process::assertNothingRan();
    expect($volume->fresh())->toBeNull();
});

it('blocks deleting the sqlite volume or database while an application is connected', function () {
    connectSqliteVolume($this->application, $this->sqlite);
    $databaseVolume = $this->sqlite->persistentStorages()->sole();

    Livewire::test(All::class, ['resource' => $this->sqlite])
        ->call('delete', $databaseVolume->id, 'password')
        ->assertDispatched('error');

    Livewire::test(Danger::class, ['resource' => $this->sqlite])
        ->call('delete', 'password')
        ->assertDispatched('error');

    expect($databaseVolume->fresh())->not->toBeNull()
        ->and(StandaloneSqlite::find($this->sqlite->id))->not->toBeNull();
});

it('does not connect a cloned application to the sqlite database', function () {
    Process::fake();
    $this->application->update(['redirect' => 'both']);
    $this->application->refresh();
    $originalVolume = connectSqliteVolume($this->application, $this->sqlite);

    $clone = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $clonedVolume = $clone->persistentStorages()->sole();

    expect($clonedVolume->standalone_sqlite_id)->toBeNull()
        ->and($clonedVolume->name)->toBe($clone->uuid.'-sqlite-data-'.$this->sqlite->uuid)
        ->and($clonedVolume->mount_path)->toBe(StandaloneSqlite::DATA_DIRECTORY)
        ->and($clonedVolume->isSharedWithAnotherResource())->toBeFalse()
        ->and($this->sqlite->connectedVolumes()->pluck('id')->all())->toBe([$originalVolume->id])
        ->and($this->sqlite->connectedApplicationNames()->all())->toBe([$this->application->name])
        ->and($originalVolume->fresh()->standalone_sqlite_id)->toBe($this->sqlite->id);
});

it('allows deleting the sqlite database once the original application is gone, even if it was cloned', function () {
    Process::fake();
    Queue::fake();
    $this->application->update(['redirect' => 'both']);
    $this->application->refresh();
    $originalVolume = connectSqliteVolume($this->application, $this->sqlite);

    clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $originalVolume->delete();

    expect($this->sqlite->fresh()->hasConnectedApplications())->toBeFalse();

    Livewire::test(Danger::class, ['resource' => $this->sqlite])
        ->call('delete', 'password')
        ->assertNotDispatched('error', fn (string $name, array $params) => str_contains($params[0] ?? '', 'is mounted by'));
});
