<?php

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StopService;
use App\Actions\Shared\MigrateResourceToDestination;
use App\Jobs\FinalizeResourceMigrationJob;
use App\Jobs\HostPathCloneJob;
use App\Jobs\VolumeCloneJob;
use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();

    $this->targetServer = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'Target Server']);
    $this->targetServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $this->targetDestination = StandaloneDocker::where('server_id', $this->targetServer->id)->firstOrFail();

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function createMigrateTestApplication($context): Application
{
    return Application::factory()->create([
        'environment_id' => $context->environment->id,
        'destination_id' => $context->destination->id,
        'destination_type' => $context->destination->getMorphClass(),
        'status' => 'running:unknown',
    ]);
}

test('migrates application destination in the database without volumes', function () {
    StopApplication::shouldRun()->once();

    $application = createMigrateTestApplication($this);

    $result = MigrateResourceToDestination::run($application, $this->targetDestination, migrateVolumes: false);

    $application->refresh();

    expect($result['async'])->toBeFalse()
        ->and($application->destination_id)->toBe($this->targetDestination->id)
        ->and($application->destination_type)->toBe($this->targetDestination->getMorphClass())
        ->and($application->status)->toStartWith('exited');
});

test('rejects migration to the same destination', function () {
    StopApplication::shouldNotRun();

    $application = createMigrateTestApplication($this);

    MigrateResourceToDestination::run($application, $this->destination, migrateVolumes: false);
})->throws(ValidationException::class);

test('rejects migration to a build server destination', function () {
    StopApplication::shouldNotRun();

    $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
    $buildServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => true,
    ]);
    $buildDestination = StandaloneDocker::where('server_id', $buildServer->id)->firstOrFail();
    $application = createMigrateTestApplication($this);

    MigrateResourceToDestination::run($application, $buildDestination, migrateVolumes: false);
})->throws(ValidationException::class);

test('chains volume clone and finalize jobs when migrating volumes across servers', function () {
    Bus::fake();
    StopApplication::shouldRun()->once();

    $application = createMigrateTestApplication($this);

    LocalPersistentVolume::create([
        'name' => $application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    LocalPersistentVolume::create([
        'name' => $application->uuid.'-bind',
        'mount_path' => '/bind',
        'host_path' => '/var/lib/coolify-test-bind',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    $result = MigrateResourceToDestination::run($application, $this->targetDestination, migrateVolumes: true);

    expect($result['async'])->toBeTrue()
        ->and($result['volume_jobs'])->toBe(2);

    // Destination must not flip until volume transfer finishes.
    $application->refresh();
    expect($application->destination_id)->toBe($this->destination->id);

    Bus::assertChained([
        VolumeCloneJob::class,
        HostPathCloneJob::class,
        FinalizeResourceMigrationJob::class,
    ]);
});

test('finalize job updates destination after volume transfer', function () {
    $application = createMigrateTestApplication($this);

    (new FinalizeResourceMigrationJob($application, $this->targetDestination))->handle();

    $application->refresh();
    expect($application->destination_id)->toBe($this->targetDestination->id)
        ->and($application->destination_type)->toBe($this->targetDestination->getMorphClass())
        ->and($application->status)->toStartWith('exited');
});

test('migrates standalone database destination and server linkage', function () {
    StopDatabase::shouldRun()->once();

    $database = StandalonePostgresql::create([
        'name' => 'pg-migrate',
        'uuid' => new_public_id(),
        'postgres_password' => 'secret',
        'postgres_user' => 'postgres',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running:unknown',
    ]);

    MigrateResourceToDestination::run($database, $this->targetDestination, migrateVolumes: false);

    $database->refresh();
    expect($database->destination_id)->toBe($this->targetDestination->id)
        ->and($database->status)->toStartWith('exited');
});

test('migrates service destination and server_id', function () {
    StopService::shouldRun()->once();

    $service = Service::create([
        'name' => 'svc-migrate',
        'uuid' => new_public_id(),
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx\n"),
        'docker_compose' => base64_encode("services:\n  app:\n    image: nginx\n"),
    ]);

    MigrateResourceToDestination::run($service, $this->targetDestination, migrateVolumes: false);

    $service->refresh();
    expect($service->destination_id)->toBe($this->targetDestination->id)
        ->and($service->server_id)->toBe($this->targetServer->id);
});

test('livewire migrateTo updates destination on same team target', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    StopApplication::shouldRun()->once();

    $application = createMigrateTestApplication($this);

    Livewire::test(ResourceOperations::class, ['resource' => $application])
        ->set('migrateVolumeData', false)
        ->call('migrateTo', $this->targetDestination->uuid)
        ->assertHasNoErrors('destination_id')
        ->assertRedirect();

    $application->refresh();
    expect($application->destination_id)->toBe($this->targetDestination->id);
});

test('livewire migrateTo rejects cross-team destination', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    StopApplication::shouldNotRun();

    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    $application = createMigrateTestApplication($this);

    Livewire::test(ResourceOperations::class, ['resource' => $application])
        ->set('migrateVolumeData', false)
        ->call('migrateTo', $otherDestination->uuid)
        ->assertHasErrors('destination_id');

    $application->refresh();
    expect($application->destination_id)->toBe($this->destination->id);
});

test('api migrates application to another destination', function () {
    StopApplication::shouldRun()->once();

    $application = createMigrateTestApplication($this);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson("/api/v1/applications/{$application->uuid}/migrate", [
        'destination_uuid' => $this->targetDestination->uuid,
        'migrate_volumes' => false,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('uuid', $application->uuid)
        ->assertJsonPath('destination_uuid', $this->targetDestination->uuid)
        ->assertJsonPath('async', false);

    $application->refresh();
    expect($application->destination_id)->toBe($this->targetDestination->id);
});

test('api migrates database to another destination', function () {
    StopDatabase::shouldRun()->once();

    $database = StandalonePostgresql::create([
        'name' => 'pg-api-migrate',
        'uuid' => new_public_id(),
        'postgres_password' => 'secret',
        'postgres_user' => 'postgres',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson("/api/v1/databases/{$database->uuid}/migrate", [
        'destination_uuid' => $this->targetDestination->uuid,
        'migrate_volumes' => false,
    ]);

    $response->assertSuccessful();
    $database->refresh();
    expect($database->destination_id)->toBe($this->targetDestination->id);
});

test('rejects migration to another destination on the same server', function () {
    StopApplication::shouldNotRun();

    $secondDestination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'second-network',
        'name' => 'Second network',
    ]);

    $application = createMigrateTestApplication($this);

    MigrateResourceToDestination::run($application, $secondDestination, migrateVolumes: false);
})->throws(ValidationException::class);

test('rejects migration to a server that is not validated and reachable', function () {
    StopApplication::shouldNotRun();

    ServerSetting::query()->where('server_id', $this->targetServer->id)->update([
        'is_reachable' => false,
        'is_usable' => false,
    ]);

    $application = createMigrateTestApplication($this);

    MigrateResourceToDestination::run($application, $this->targetDestination, migrateVolumes: false);
})->throws(ValidationException::class);

test('resource operations migrate list only includes other functional servers', function () {
    $unreachableServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Unreachable Server',
    ]);
    $unreachableServer->settings()->update([
        'is_reachable' => false,
        'is_usable' => false,
    ]);

    $application = createMigrateTestApplication($this);
    $application->load(['destination.server', 'environment.project']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(ResourceOperations::class, ['resource' => $application]);

    $servers = $component->get('servers');
    $serverIds = collect($servers)->pluck('id')->all();

    expect($serverIds)->toContain($this->server->id)
        ->and($serverIds)->toContain($this->targetServer->id)
        ->and($serverIds)->toContain($unreachableServer->id);

    $view = file_get_contents(resource_path('views/livewire/project/shared/resource-operations.blade.php'));

    expect($view)
        ->toContain('server.is_functional && server.id != this.currentServerId')
        ->toContain("'is_functional' => \$server->isFunctional()");
});

test('migration is unavailable outside development mode', function () {
    config(['app.env' => 'production']);
    StopApplication::shouldNotRun();

    $application = createMigrateTestApplication($this);

    MigrateResourceToDestination::run($application, $this->targetDestination, migrateVolumes: false);
})->throws(ValidationException::class, 'Resource migration is only available in development mode.');

test('resource operations only shows migration in development mode', function () {
    $application = createMigrateTestApplication($this);
    $application->load(['destination.server', 'environment.project']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(ResourceOperations::class, ['resource' => $application])
        ->assertSee('Migrate to another server')
        ->assertSee('Dev');

    config(['app.env' => 'production']);

    Livewire::test(ResourceOperations::class, ['resource' => $application])
        ->assertDontSee('Migrate to another server');
});

test('migration api is unavailable outside development mode', function () {
    config(['app.env' => 'production']);
    StopApplication::shouldNotRun();

    $application = createMigrateTestApplication($this);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson("/api/v1/applications/{$application->uuid}/migrate", [
        'destination_uuid' => $this->targetDestination->uuid,
        'migrate_volumes' => false,
    ])->assertNotFound();

    expect($application->fresh()->destination_id)->toBe($this->destination->id);
});
