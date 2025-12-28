<?php

use App\Livewire\Security\DockerRegistry\Create;
use App\Livewire\Security\DockerRegistry\Show;
use App\Models\DockerRegistry;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create required InstanceSettings for the application
    InstanceSettings::create([
        'id' => 0,
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('creates a docker registry with trimmed credentials and url', function () {
    Livewire::test(Create::class)
        ->set('name', 'Registry One')
        ->set('description', 'My registry')
        ->set('registryUrl', '  ghcr.io  ')
        ->set('username', '  registry-user ')
        ->set('password', '  registry-token ')
        ->call('createRegistry')
        ->assertRedirect(route('security.docker-registry.index'));

    $registry = DockerRegistry::first();

    expect($registry)->not->toBeNull()
        ->and($registry->name)->toBe('Registry One')
        ->and($registry->description)->toBe('My registry')
        ->and($registry->registry_url)->toBe('ghcr.io')
        ->and($registry->username)->toBe('registry-user')
        ->and($registry->password)->toBe('registry-token')
        ->and($registry->team_id)->toBe($this->team->id);
});

it('requires both username and password when creating a registry', function () {
    Livewire::test(Create::class)
        ->set('name', 'Registry Two')
        ->set('username', 'registry-user')
        ->set('password', '')
        ->call('createRegistry')
        ->assertHasErrors('username')
        ->assertHasErrors('password');
});

it('allows clearing credentials on the registry show component', function () {
    $registry = DockerRegistry::create([
        'name' => 'Registry Three',
        'registry_url' => 'ghcr.io',
        'username' => 'registry-user',
        'password' => 'registry-token',
        'team_id' => $this->team->id,
    ]);

    Livewire::withQueryParams(['registry_uuid' => $registry->uuid])
        ->test(Show::class)
        ->set('username', '')
        ->set('password', '')
        ->call('save')
        ->assertDispatched('success');

    $registry->refresh();

    expect($registry->username)->toBeNull()
        ->and($registry->password)->toBeNull();
});

it('requires both username and password when changing credentials', function () {
    $registry = DockerRegistry::create([
        'name' => 'Registry Four',
        'registry_url' => 'ghcr.io',
        'username' => 'registry-user',
        'password' => 'registry-token',
        'team_id' => $this->team->id,
    ]);

    Livewire::withQueryParams(['registry_uuid' => $registry->uuid])
        ->test(Show::class)
        ->set('username', 'new-user')
        ->set('password', '')
        ->call('save')
        ->assertHasErrors('username')
        ->assertHasErrors('password');
});

it('can delete a docker registry that is not in use', function () {
    $registry = DockerRegistry::create([
        'name' => 'Registry to Delete',
        'registry_url' => 'ghcr.io',
        'username' => 'registry-user',
        'password' => 'registry-token',
        'team_id' => $this->team->id,
    ]);

    Livewire::withQueryParams(['registry_uuid' => $registry->uuid])
        ->test(Show::class)
        ->call('delete')
        ->assertRedirect(route('security.docker-registry.index'));

    expect(DockerRegistry::find($registry->id))->toBeNull();
});

it('cannot delete a docker registry that is in use by an application', function () {
    $registry = DockerRegistry::create([
        'name' => 'Registry in Use',
        'registry_url' => 'ghcr.io',
        'username' => 'registry-user',
        'password' => 'registry-token',
        'team_id' => $this->team->id,
    ]);

    // Create an application using this registry
    $application = \App\Models\Application::factory()->create([
        'docker_registry_id' => $registry->id,
    ]);

    Livewire::withQueryParams(['registry_uuid' => $registry->uuid])
        ->test(Show::class)
        ->call('delete');

    // Registry should still exist because it's in use
    expect(DockerRegistry::find($registry->id))->not->toBeNull();
});
