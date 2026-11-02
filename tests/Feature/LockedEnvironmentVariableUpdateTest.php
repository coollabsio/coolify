<?php

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

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create(['environment_id' => $environment->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createLockedVariableForUpdateTest(bool $required = false, ?string $value = 'locked-secret'): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => $value,
        'is_shown_once' => true,
        'is_required' => $required,
        'is_runtime' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => test()->application->id,
    ]);
}

test('changing a flag of a locked variable keeps its value', function (bool $required) {
    $env = createLockedVariableForUpdateTest(required: $required);

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->assertSet('value', null)
        ->set('is_runtime', true)
        ->call('submit')
        ->assertDispatched('success')
        ->assertNotDispatched('error')
        ->assertSet('value', null);

    $env->refresh();
    expect($env->value)->toBe('locked-secret')
        ->and($env->is_runtime)->toBeTrue()
        ->and((bool) $env->is_shown_once)->toBeTrue();
})->with(['not required' => false, 'required' => true]);

test('a locked required variable with an empty stored value is still rejected', function () {
    $env = createLockedVariableForUpdateTest(required: true, value: null);

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->set('is_runtime', true)
        ->call('submit')
        ->assertDispatched('error', 'Required environment variables cannot be empty.');

    expect($env->refresh()->is_runtime)->toBeFalse();
});

test('submit cannot replace the value of a locked variable or unlock it', function () {
    $env = createLockedVariableForUpdateTest();

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->set('value', 'replaced')
        ->set('is_shown_once', false)
        ->call('submit')
        ->assertDispatched('success');

    $env->refresh();
    expect($env->value)->toBe('locked-secret')
        ->and((bool) $env->is_shown_once)->toBeTrue();
});

test('a failed update of a locked variable does not expose its value', function () {
    $env = createLockedVariableForUpdateTest(required: true);

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->set('comment', str_repeat('a', 257))
        ->call('submit')
        ->assertHasErrors('comment')
        ->assertSet('value', null);
});

test('changing the comment of a locked shared variable keeps its value', function () {
    $env = SharedEnvironmentVariable::create([
        'key' => 'TEAM_TOKEN',
        'value' => 'locked-shared-secret',
        'is_shown_once' => true,
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);

    Livewire::test(Show::class, ['env' => $env, 'type' => 'team'])
        ->call('loadValues')
        ->set('comment', 'rotated monthly')
        ->call('submit')
        ->assertDispatched('success');

    $env->refresh();
    expect($env->value)->toBe('locked-shared-secret')
        ->and($env->comment)->toBe('rotated monthly');
});
