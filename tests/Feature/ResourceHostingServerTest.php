<?php

use App\Livewire\Project\CloneMe;
use App\Livewire\Project\New\Select as ResourceSelect;
use App\Livewire\Project\New\SimpleDockerfile;
use App\Livewire\Project\Shared\ResourceOperations;
use App\Livewire\Server\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('resource-hosting-test', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => false,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);
    $this->server->refresh()->load('settings');

    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function resourceHostingApiHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ];
}

function createResourceHostingTestApplication(object $test): Application
{
    return Application::factory()->create([
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
    ]);
}

test('only eligible deployment servers can host resources', function () {
    expect($this->server->canHostResources())->toBeTrue();

    $this->server->settings->update(['is_build_server' => true]);

    expect($this->server->fresh()->canHostResources())->toBeFalse();
});

test('a populated build server can be changed back to a deployment server', function () {
    createResourceHostingTestApplication($this);
    $this->server->settings()->update(['is_build_server' => true]);

    Livewire::actingAs($this->user)
        ->test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('isBuildServer', true)
        ->assertSet('isBuildServerLocked', false)
        ->set('isBuildServer', false)
        ->assertHasNoErrors();

    expect((bool) $this->server->settings->fresh()->is_build_server)->toBeFalse();
});

test('a populated deployment server cannot be changed into a build server', function () {
    createResourceHostingTestApplication($this);

    Livewire::actingAs($this->user)
        ->test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('isBuildServer', false)
        ->assertSet('isBuildServerLocked', true)
        ->set('isBuildServer', true)
        ->assertSet('isBuildServer', false);

    expect((bool) $this->server->settings->fresh()->is_build_server)->toBeFalse();
});

test('resource selection keeps excluded build servers visible for explanation', function () {
    $this->actingAs($this->user);
    $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
    $buildServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => true,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);

    $component = new ResourceSelect;
    $component->loadServers();

    expect($component->servers->pluck('id'))->toContain($this->server->id)
        ->not->toContain($buildServer->id)
        ->and($component->buildServers->pluck('id'))->toContain($buildServer->id)
        ->and($component->allServers->pluck('id'))->toContain($this->server->id, $buildServer->id);
});

test('application API rejects build servers', function () {
    $this->server->settings()->update(['is_build_server' => true]);

    $this->withHeaders(resourceHostingApiHeaders($this->bearerToken))
        ->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => 'latest',
            'ports_exposes' => '80',
            'instant_deploy' => false,
        ])
        ->assertUnprocessable()
        ->assertInvalid(['server_uuid']);

    expect(Application::count())->toBe(0);
});

test('database API rejects build servers', function () {
    $this->server->settings()->update(['is_build_server' => true]);

    $this->withHeaders(resourceHostingApiHeaders($this->bearerToken))
        ->postJson('/api/v1/databases/postgresql', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'instant_deploy' => false,
        ])
        ->assertUnprocessable()
        ->assertInvalid(['server_uuid']);
});

test('service API rejects build servers', function () {
    $this->server->settings()->update(['is_build_server' => true]);

    $this->withHeaders(resourceHostingApiHeaders($this->bearerToken))
        ->postJson('/api/v1/services', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx:latest"),
            'instant_deploy' => false,
        ])
        ->assertUnprocessable()
        ->assertInvalid(['server_uuid']);
});

test('server API rejects enabling build mode when resources exist', function () {
    createResourceHostingTestApplication($this);

    $this->withHeaders(resourceHostingApiHeaders($this->bearerToken))
        ->patchJson('/api/v1/servers/'.$this->server->uuid, [
            'is_build_server' => true,
        ])
        ->assertUnprocessable()
        ->assertInvalid(['is_build_server']);

    expect((bool) $this->server->settings->fresh()->is_build_server)->toBeFalse();
});

test('server API allows disabling build mode when resources exist', function () {
    createResourceHostingTestApplication($this);
    $this->server->settings()->update(['is_build_server' => true]);

    $this->withHeaders(resourceHostingApiHeaders($this->bearerToken))
        ->patchJson('/api/v1/servers/'.$this->server->uuid, [
            'is_build_server' => false,
        ])
        ->assertCreated();

    expect((bool) $this->server->settings->fresh()->is_build_server)->toBeFalse();
});

test('resource APIs still accept deployment servers', function () {
    $headers = resourceHostingApiHeaders($this->bearerToken);

    $this->withHeaders($headers)
        ->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => 'latest',
            'ports_exposes' => '80',
            'instant_deploy' => false,
        ])
        ->assertCreated();

    $this->withHeaders($headers)
        ->postJson('/api/v1/databases/postgresql', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'instant_deploy' => false,
        ])
        ->assertCreated();

    $this->withHeaders($headers)
        ->postJson('/api/v1/services', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx:latest"),
            'instant_deploy' => false,
        ])
        ->assertCreated();
});

test('a crafted web request cannot create a resource on a build server', function () {
    $this->actingAs($this->user);
    $this->server->settings()->update(['is_build_server' => true]);

    $url = route('project.resource.create', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]).'?type=postgresql&destination='.$this->destination->uuid.'&server_id='.$this->server->id.'&database_image=postgres:16-alpine';

    $this->get($url)->assertRedirectToRoute('dashboard');

    expect(StandalonePostgresql::count())->toBe(0);
});

test('a manipulated resource form cannot submit to a build server', function () {
    $this->actingAs($this->user);
    $this->server->settings()->update(['is_build_server' => true]);
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];

    expect(fn () => Livewire::withUrlParams(['destination' => $this->destination->uuid])
        ->test(SimpleDockerfile::class, $routeParameters)
        ->set('dockerfile', "FROM nginx\nCMD [\"nginx\"]\n")
        ->call('submit'))
        ->toThrow(Exception::class, 'Destination not found.');

    expect(Application::count())->toBe(0);
});

test('a manipulated project clone cannot target a build server', function () {
    $this->actingAs($this->user);
    $this->server->settings()->update(['is_build_server' => true]);
    $projectCount = Project::count();

    Livewire::test(CloneMe::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('selectedDestination', $this->destination->id)
        ->set('newName', 'blocked-clone')
        ->call('clone', 'project');

    expect(Project::count())->toBe($projectCount);
});

test('a manipulated clone request cannot target a build server', function () {
    $this->actingAs($this->user);
    $source = createResourceHostingTestApplication($this);
    $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
    $buildServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => true,
    ]);
    $buildDestination = StandaloneDocker::where('server_id', $buildServer->id)->firstOrFail();

    Livewire::test(ResourceOperations::class, ['resource' => $source])
        ->call('cloneTo', $buildDestination->id)
        ->assertHasErrors(['destination_id']);

    expect(Application::count())->toBe(1);
});

test('resource operations explains why build servers cannot be clone targets', function () {
    $this->actingAs($this->user);
    $source = createResourceHostingTestApplication($this);
    $buildServer = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'Dedicated Builder']);
    $buildServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => true,
    ]);

    Livewire::test(ResourceOperations::class, ['resource' => $source])
        ->assertSet('buildServers', fn ($servers) => $servers->contains('id', $buildServer->id))
        ->assertSee('Dedicated Builder')
        ->assertSee('Build server — cannot host resources');
});
