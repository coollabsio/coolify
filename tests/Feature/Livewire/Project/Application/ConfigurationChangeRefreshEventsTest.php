<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All as EnvironmentVariableAll;
use App\Livewire\Project\Shared\HealthChecks;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create(['environment_id' => $environment->id])->refresh();
});

it('refreshes configuration changes after health check saves', function (string $method) {
    Livewire::test(HealthChecks::class, ['resource' => $this->application])
        ->call($method)
        ->assertHasNoErrors()
        ->assertDispatched('configurationChanged');
})->with(['instantSave', 'submit', 'toggleHealthcheck']);

it('refreshes configuration changes after environment variable settings are saved', function () {
    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->set('use_build_secrets', true)
        ->call('instantSave')
        ->assertHasNoErrors()
        ->assertDispatched('configurationChanged');
});

it('persists environment variable order as an application setting', function () {
    expect($this->application->settings->is_env_sorting_enabled)->toBeFalse();

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->application])
        ->assertSee('Environment variable order')
        ->assertSee('Build secrets')
        ->assertDontSeeHtml('>Sort</button>')
        ->call('loadEnvironmentVariables')
        ->set('is_env_sorting_enabled', true)
        ->call('instantSave')
        ->assertHasNoErrors()
        ->assertDispatched('configurationChanged')
        ->assertSet('is_env_sorting_enabled', true);

    expect($this->application->settings->fresh()->is_env_sorting_enabled)->toBeTrue();
});
