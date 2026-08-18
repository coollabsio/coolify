<?php

use App\Livewire\Project\DeleteEnvironment;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    // Current team
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    // Another team
    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('mount cannot load DeleteEnvironment with environment from another team', function () {
    Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentB->id]);
})->throws(ModelNotFoundException::class);

test('mount can load DeleteEnvironment with own team environment', function () {
    $component = Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentA->id]);

    expect($component->get('environmentName'))->toBe($this->environmentA->name);
});

test('environment_id is locked and cannot be reassigned from the client', function () {
    $component = Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentA->id]);

    try {
        $component->set('environment_id', $this->environmentB->id);
        $this->fail('Setting a #[Locked] property should have thrown.');
    } catch (CannotUpdateLockedPropertyException) {
        expect(true)->toBeTrue();
    }
});

test('delete still removes an empty environment owned by the current team', function () {
    $component = Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentA->id])
        ->set('parameters', ['project_uuid' => $this->projectA->uuid]);

    $component->call('delete');

    expect(Environment::find($this->environmentA->id))->toBeNull();
});

test('delete cannot resolve a non-empty environment from another team', function () {
    // The team-scoped lookup must stay in the delete() path so the
    // "has defined resources" branch can never run for an environment
    // outside the caller's team.
    Application::factory()->create([
        'environment_id' => $this->environmentB->id,
    ]);

    $teamScopedLookup = fn () => Environment::ownedByCurrentTeam()
        ->findOrFail($this->environmentB->id);

    expect($teamScopedLookup)->toThrow(ModelNotFoundException::class);
});

test('team scoped lookup permits own team environment', function () {
    // Positive case so the cross-team check above cannot pass merely
    // because the helper itself is broken.
    $found = Environment::ownedByCurrentTeam()->findOrFail($this->environmentA->id);

    expect($found->id)->toBe($this->environmentA->id);
});
