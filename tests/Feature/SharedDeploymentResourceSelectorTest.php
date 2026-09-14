<?php

use App\Livewire\Project\New\Select as ResourceSelect;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(
        fn () => InstanceSettings::firstOrCreate(['id' => 0])
    );

    $this->team = Team::factory()->create();
    $this->ownerTeam = Team::factory()->create();

    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, [
        'role' => 'owner',
    ]);
    $this->user->load('teams');

    $this->ownServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Own deployment server',
    ]);

    $this->sharedServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
        'name' => 'Shared deployment server',
    ]);

    foreach ([$this->ownServer, $this->sharedServer] as $server) {
        $server->settings()->update([
            'is_build_server' => false,
            'is_reachable' => true,
            'is_usable' => true,
            'is_swarm_worker' => false,
            'is_swarm_manager' => false,
            'force_disabled' => false,
        ]);
    }

    $this->buildServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Own build server',
    ]);

    $this->buildServer->settings()->update([
        'is_build_server' => true,
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_worker' => false,
        'is_swarm_manager' => false,
        'force_disabled' => false,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('application selector includes an authorized shared deployment server', function () {
    $this->sharedServer->sharedTeams()->attach($this->team->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $component = new ResourceSelect;
    $component->loadServers();
    $component->setType('public');

    expect($component->servers->pluck('id'))
        ->toContain($this->ownServer->id, $this->sharedServer->id)
        ->not->toContain($this->buildServer->id);
});

test('application selector excludes an unauthorized external server', function () {
    $component = new ResourceSelect;
    $component->loadServers();
    $component->setType('public');

    expect($component->servers->pluck('id'))
        ->toContain($this->ownServer->id)
        ->not->toContain($this->sharedServer->id);
});

test('build-only sharing does not expose the external server', function () {
    $this->sharedServer->sharedTeams()->attach($this->team->id, [
        'can_build' => true,
        'can_deploy' => false,
    ]);

    $component = new ResourceSelect;
    $component->loadServers();
    $component->setType('docker-image');

    expect($component->servers->pluck('id'))
        ->not->toContain($this->sharedServer->id);
});

test('service selector remains restricted to owned servers', function () {
    $this->sharedServer->sharedTeams()->attach($this->team->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $component = new ResourceSelect;
    $component->loadServers();
    $component->setType('docker-compose-empty');

    expect($component->servers->pluck('id'))
        ->toContain($this->ownServer->id)
        ->not->toContain($this->sharedServer->id);
});

test('manipulated server id cannot select an unauthorized server', function () {
    $component = new ResourceSelect;
    $component->loadServers();
    $component->type = 'public';

    expect(
        fn () => $component->setServer($this->sharedServer->id)
    )->toThrow(ModelNotFoundException::class);
});

test('authorized shared server can be selected for an application', function () {
    $this->sharedServer->sharedTeams()->attach($this->team->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $component = new ResourceSelect;
    $component->parameters = [
        'project_uuid' => 'test-project',
        'environment_uuid' => 'test-environment',
    ];
    $component->loadServers();
    $component->type = 'public';
    $component->setServer($this->sharedServer->id);

    expect($component->server->id)
        ->toBe($this->sharedServer->id)
        ->and($component->server_id)
        ->toBe((string) $this->sharedServer->id);
});

test('shared deployment application types are defined centrally', function () {
    expect(shared_deployment_application_types())->toBe([
        'public',
        'private-deploy-key',
        'private-gh-app',
        'private-gitlab-app',
        'dockerfile',
        'docker-image',
    ]);

    foreach (shared_deployment_application_types() as $type) {
        expect(is_shared_deployment_application_type($type))->toBeTrue();
    }

    expect(is_shared_deployment_application_type('docker-compose-empty'))
        ->toBeFalse()
        ->and(is_shared_deployment_application_type('postgresql'))
        ->toBeFalse()
        ->and(is_shared_deployment_application_type('one-click-service-ghost'))
        ->toBeFalse();
});

test('deployable destination resolver returns an authorized shared destination', function () {
    $this->sharedServer->sharedTeams()->attach($this->team->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $destination = $this->sharedServer
        ->standaloneDockers()
        ->firstOrFail();

    $resolved = find_deployable_resource_destination_for_current_team(
        $destination->uuid
    );

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($destination->id);
});

test('deployable destination resolver rejects a revoked shared destination', function () {
    $this->sharedServer->sharedTeams()->attach($this->team->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $destination = $this->sharedServer
        ->standaloneDockers()
        ->firstOrFail();

    $this->sharedServer->sharedTeams()->updateExistingPivot(
        $this->team->id,
        ['can_deploy' => false]
    );

    expect(
        find_deployable_resource_destination_for_current_team(
            $destination->uuid
        )
    )->toBeNull();
});

test('resource creation page accepts a shared destination for an application', function () {
    $this->sharedServer->sharedTeams()->attach($this->team->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $environment = $project->environments()->firstOrFail();
    $destination = $this->sharedServer
        ->standaloneDockers()
        ->firstOrFail();

    $url = route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]).'?type=docker-image&destination='.$destination->uuid
        .'&server_id='.$this->sharedServer->id;

    $this->get($url)->assertOk();
});

test('resource creation page rejects a shared destination for docker compose', function () {
    $this->sharedServer->sharedTeams()->attach($this->team->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $environment = $project->environments()->firstOrFail();
    $destination = $this->sharedServer
        ->standaloneDockers()
        ->firstOrFail();

    $url = route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]).'?type=docker-compose-empty&destination='.$destination->uuid
        .'&server_id='.$this->sharedServer->id;

    $this->get($url)->assertRedirectToRoute('dashboard');
});
