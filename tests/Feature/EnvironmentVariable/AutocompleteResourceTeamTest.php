<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Add;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->resourceTeam = Team::factory()->create();
    $this->sessionTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->resourceTeam, ['role' => 'member']);
    $this->user->teams()->attach($this->sessionTeam, ['role' => 'owner']);

    $this->project = Project::factory()->create(['team_id' => $this->resourceTeam->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);

    SharedEnvironmentVariable::create(['key' => 'RESOURCE_TEAM_VAR', 'value' => 'a', 'type' => 'team', 'team_id' => $this->resourceTeam->id]);
    SharedEnvironmentVariable::create(['key' => 'SESSION_TEAM_VAR', 'value' => 'b', 'type' => 'team', 'team_id' => $this->sessionTeam->id]);
    SharedEnvironmentVariable::create(['key' => 'RESOURCE_PROJECT_VAR', 'value' => 'c', 'type' => 'project', 'team_id' => $this->resourceTeam->id, 'project_id' => $this->project->id]);

    $this->parameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ];

    $this->actingAs($this->user);
    session(['currentTeam' => $this->sessionTeam]);
});

test('add suggests shared variables of the resource team when the session team differs', function () {
    $component = Livewire::test(Add::class, ['resource' => $this->application])
        ->set('parameters', $this->parameters);

    $suggestions = $component->instance()->availableSharedVariables();

    expect($suggestions['team'])->toBe(['RESOURCE_TEAM_VAR'])
        ->and($suggestions['project'])->toBe(['RESOURCE_PROJECT_VAR']);
});

test('show suggests shared variables of the resource team when the session team differs', function () {
    $env = EnvironmentVariable::create([
        'key' => 'APP_VAR',
        'value' => 'x',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $component = Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->set('parameters', $this->parameters);

    $suggestions = $component->instance()->availableSharedVariables();

    expect($suggestions['team'])->toBe(['RESOURCE_TEAM_VAR'])
        ->and($suggestions['project'])->toBe(['RESOURCE_PROJECT_VAR']);
});

test('add without a resource keeps suggesting the session team variables', function () {
    $component = Livewire::test(Add::class, ['shared' => true]);

    expect($component->instance()->availableSharedVariables()['team'])->toBe(['SESSION_TEAM_VAR']);
});
