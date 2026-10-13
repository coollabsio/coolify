<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($this->user, ['role' => 'owner']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    $this->environmentVariable = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $this->actingAs($this->user);
});

test('environment variable editor closes only after a successful update', function () {
    Livewire::test(Show::class, ['env' => $this->environmentVariable, 'type' => 'application'])
        ->set('comment', 'Updated in the editor')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('environment-variable-updated', envId: $this->environmentVariable->id);
});

test('environment variable editor stays open when validation fails', function () {
    Livewire::test(Show::class, ['env' => $this->environmentVariable, 'type' => 'application'])
        ->set('valuesLoaded', true)
        ->set('is_required', true)
        ->set('value', '')
        ->call('submit')
        ->assertNotDispatched('environment-variable-updated');
});
