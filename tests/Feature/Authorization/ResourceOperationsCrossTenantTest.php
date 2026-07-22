<?php

use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    // Team A (attacker's team)
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::where('server_id', $this->serverA->id)->firstOrFail();
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    $this->applicationA = Application::factory()->create([
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
    ]);

    // Team B (victim's team)
    $this->teamB = Team::factory()->create();
    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::where('server_id', $this->serverB->id)->firstOrFail();
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('cloneTo rejects destination belonging to another team', function () {
    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('cloneTo', $this->destinationB->uuid)
        ->assertHasErrors('destination_id');

    // Ensure no cross-tenant application was created
    expect(Application::where('destination_id', $this->destinationB->id)->exists())->toBeFalse();
});

test('cloneTo allows destination belonging to own team', function () {
    $secondDestination = StandaloneDocker::factory()->create([
        'server_id' => $this->serverA->id,
        'network' => 'second-destination',
    ]);

    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('cloneTo', $secondDestination->uuid)
        ->assertHasNoErrors('destination_id');

    expect(Application::count())->toBe(2);
});

test('moveTo rejects environment belonging to another team', function () {
    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('moveTo', $this->environmentB->id);

    // Resource should still be in original environment
    $this->applicationA->refresh();
    expect($this->applicationA->environment_id)->toBe($this->environmentA->id);
});

test('moveTo allows environment belonging to own team', function () {
    $secondEnvironment = Environment::factory()->create(['project_id' => $this->projectA->id]);

    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('moveTo', $secondEnvironment->id)
        ->assertRedirect();

    $this->applicationA->refresh();
    expect($this->applicationA->environment_id)->toBe($secondEnvironment->id);
});

test('StandaloneDockerPolicy denies update for cross-team user', function () {
    expect($this->userA->can('update', $this->destinationB))->toBeFalse();
});

test('StandaloneDockerPolicy allows update for same-team user', function () {
    expect($this->userA->can('update', $this->destinationA))->toBeTrue();
});
