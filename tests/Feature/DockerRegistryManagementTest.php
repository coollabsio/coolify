<?php

use App\Models\DockerRegistry;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    $this->actingAs($this->user);
    $this->user->currentTeam = $this->team;
});

it('can create a docker registry', function () {
    $registry = DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Docker Hub',
        'registry_url' => 'https://index.docker.io/v1/',
        'username' => 'testuser',
        'password' => 'testpass',
        'is_active' => true,
    ]);

    expect($registry)->toBeInstanceOf(DockerRegistry::class)
        ->and($registry->name)->toBe('Docker Hub')
        ->and($registry->registry_url)->toBe('https://index.docker.io/v1/')
        ->and($registry->username)->toBe('testuser')
        ->and($registry->is_active)->toBeTrue();
});

it('can update a docker registry', function () {
    $registry = DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Docker Hub',
        'registry_url' => 'https://index.docker.io/v1/',
        'username' => 'testuser',
        'password' => 'testpass',
        'is_active' => true,
    ]);

    $registry->update([
        'name' => 'Updated Registry',
        'username' => 'newuser',
    ]);

    $registry->refresh();

    expect($registry->name)->toBe('Updated Registry')
        ->and($registry->username)->toBe('newuser');
});

it('can delete a docker registry', function () {
    $registry = DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Docker Hub',
        'registry_url' => 'https://index.docker.io/v1/',
        'username' => 'testuser',
        'password' => 'testpass',
        'is_active' => true,
    ]);

    $registryId = $registry->id;
    $registry->delete();

    expect(DockerRegistry::find($registryId))->toBeNull();
});

it('can toggle registry active status', function () {
    $registry = DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Docker Hub',
        'registry_url' => 'https://index.docker.io/v1/',
        'username' => 'testuser',
        'password' => 'testpass',
        'is_active' => true,
    ]);

    $registry->is_active = false;
    $registry->save();

    $registry->refresh();

    expect($registry->is_active)->toBeFalse();
});

it('prevents duplicate registries for same server', function () {
    DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Docker Hub',
        'registry_url' => 'https://index.docker.io/v1/',
        'username' => 'testuser',
        'password' => 'testpass',
        'is_active' => true,
    ]);

    $this->expectException(\Illuminate\Validation\ValidationException::class);

    DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Docker Hub 2',
        'registry_url' => 'https://index.docker.io/v1/',
        'username' => 'anotheruser',
        'password' => 'anotherpass',
        'is_active' => true,
    ]);
});

it('can filter active registries', function () {
    DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Active Registry',
        'registry_url' => 'ghcr.io',
        'username' => 'user1',
        'password' => 'pass1',
        'is_active' => true,
    ]);

    DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Inactive Registry',
        'registry_url' => 'registry.gitlab.com',
        'username' => 'user2',
        'password' => 'pass2',
        'is_active' => false,
    ]);

    $activeRegistries = DockerRegistry::where('server_id', $this->server->id)
        ->active()
        ->get();

    expect($activeRegistries)->toHaveCount(1)
        ->and($activeRegistries->first()->name)->toBe('Active Registry');
});

it('belongs to a server', function () {
    $registry = DockerRegistry::create([
        'server_id' => $this->server->id,
        'name' => 'Docker Hub',
        'registry_url' => 'https://index.docker.io/v1/',
        'username' => 'testuser',
        'password' => 'testpass',
        'is_active' => true,
    ]);

    expect($registry->server)->toBeInstanceOf(Server::class)
        ->and($registry->server->id)->toBe($this->server->id);
});
