<?php

use App\Livewire\Project\CloneMe;
use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Services\ProjectConfig\ManagedResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Clones of a coolify.json resource are not managed: a suspended link keeps the keys, and the key is unique per environment. */
beforeEach(function () {
    $this->withoutVite();
    $this->fx = projectConfigFixture();
    EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id, 'suspended_at' => now()]);
    $this->actingAs($this->fx['user']);
    $this->onDestination = [
        'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id,
        'destination_type' => $this->fx['destination']->getMorphClass(),
    ];
});

function cloneManagedVariable($resource): void
{
    ManagedResources::whileApplying(fn () => EnvironmentVariable::create([
        'key' => 'TOKEN', 'value' => 'x', 'config_meta' => ['form' => 'value'],
        'resourceable_type' => $resource->getMorphClass(), 'resourceable_id' => $resource->id,
    ]));
}

function cloneVariablesAreUnmanaged($resource): bool
{
    return EnvironmentVariable::where('resourceable_type', $resource->getMorphClass())->where('resourceable_id', $resource->id)
        ->whereNotNull('config_meta')->doesntExist();
}

it('clones an application into the same environment without its config key', function () {
    $application = Application::factory()->create([...$this->onDestination, 'config_key' => 'api']);
    $application->settings->update(['is_container_label_readonly_enabled' => false]);
    cloneManagedVariable($application);

    $clone = clone_application($application->fresh(), $this->fx['destination'], ['environment_id' => $this->fx['environment']->id]);

    expect($clone->fresh()->config_key)->toBeNull()
        ->and(cloneVariablesAreUnmanaged($clone))->toBeTrue()
        ->and($application->fresh()->config_key)->toBe('api');
});

it('clones a database into the same environment without its config key', function () {
    $database = StandalonePostgresql::forceCreate([
        ...$this->onDestination, 'name' => 'pg', 'uuid' => new_public_id(), 'postgres_password' => 'secret',
        'postgres_user' => 'postgres', 'postgres_db' => 'postgres', 'status' => 'exited', 'config_key' => 'db',
    ]);
    cloneManagedVariable($database);

    Livewire::test(ResourceOperations::class, ['resource' => $database])
        ->call('cloneTo', $this->fx['destination']->uuid)
        ->assertRedirect();

    $clone = StandalonePostgresql::whereKeyNot($database->id)->sole();
    expect($clone->config_key)->toBeNull()
        ->and(cloneVariablesAreUnmanaged($clone))->toBeTrue();
});

it('clones a service into the same environment without its config key and meta', function () {
    $service = Service::factory()->create([
        ...$this->onDestination, 'server_id' => $this->fx['server']->id, 'config_key' => 'web',
        'config_meta' => ['managed_domains' => ['web']], 'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    cloneManagedVariable($service);

    $clone = clone_service($service->fresh(), $this->fx['destination']);

    expect($clone->fresh()->config_key)->toBeNull()
        ->and($clone->fresh()->config_meta)->toBeNull()
        ->and(cloneVariablesAreUnmanaged($clone))->toBeTrue();
});

it('clones an environment into resources that coolify.json does not manage', function () {
    $application = Application::factory()->create([...$this->onDestination, 'config_key' => 'api']);
    $application->settings->update(['is_container_label_readonly_enabled' => false]);
    StandalonePostgresql::forceCreate([
        ...$this->onDestination, 'name' => 'pg', 'uuid' => new_public_id(), 'postgres_password' => 'secret',
        'postgres_user' => 'postgres', 'postgres_db' => 'postgres', 'status' => 'exited', 'config_key' => 'db',
    ]);

    Livewire::test(CloneMe::class, ['project_uuid' => $this->fx['project']->uuid, 'environment_uuid' => $this->fx['environment']->uuid])
        ->set('selectedDestination', $this->fx['destination']->uuid)
        ->set('newName', 'copy')
        ->call('clone', 'environment')
        ->assertNotDispatched('error');

    $environment = $this->fx['project']->environments()->where('name', 'copy')->sole();
    expect($environment->applications()->sole()->config_key)->toBeNull()
        ->and(StandalonePostgresql::where('environment_id', $environment->id)->sole()->config_key)->toBeNull();
});
