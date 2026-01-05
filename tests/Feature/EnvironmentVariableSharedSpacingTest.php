<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user and team
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);

    // Create project and environment
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    // Create application for testing
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);
});

test('shared variable preserves spacing in reference', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => '{{ project.aaa }}',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $env->refresh();
    expect($env->value)->toBe('{{ project.aaa }}');
});

test('shared variable preserves no-space format', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => '{{project.aaa}}',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $env->refresh();
    expect($env->value)->toBe('{{project.aaa}}');
});

test('shared variable with spaces resolves correctly', function () {
    // Create shared variable
    $shared = SharedEnvironmentVariable::create([
        'key' => 'TEST_KEY',
        'value' => 'test-value-123',
        'type' => 'project',
        'project_id' => $this->project->id,
        'team_id' => $this->team->id,
    ]);

    // Create env var with spaces
    $env = EnvironmentVariable::create([
        'key' => 'MY_VAR',
        'value' => '{{ project.TEST_KEY }}',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    // Verify it resolves correctly
    $realValue = $env->real_value;
    expect($realValue)->toBe('test-value-123');
});

test('shared variable without spaces resolves correctly', function () {
    // Create shared variable
    $shared = SharedEnvironmentVariable::create([
        'key' => 'TEST_KEY',
        'value' => 'test-value-456',
        'type' => 'project',
        'project_id' => $this->project->id,
        'team_id' => $this->team->id,
    ]);

    // Create env var without spaces
    $env = EnvironmentVariable::create([
        'key' => 'MY_VAR',
        'value' => '{{project.TEST_KEY}}',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    // Verify it resolves correctly
    $realValue = $env->real_value;
    expect($realValue)->toBe('test-value-456');
});

test('shared variable with extra internal spaces resolves correctly', function () {
    // Create shared variable
    $shared = SharedEnvironmentVariable::create([
        'key' => 'TEST_KEY',
        'value' => 'test-value-789',
        'type' => 'project',
        'project_id' => $this->project->id,
        'team_id' => $this->team->id,
    ]);

    // Create env var with multiple spaces
    $env = EnvironmentVariable::create([
        'key' => 'MY_VAR',
        'value' => '{{  project.TEST_KEY  }}',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    // Verify it resolves correctly (parser trims when extracting)
    $realValue = $env->real_value;
    expect($realValue)->toBe('test-value-789');
});

test('is_shared attribute detects variable with spaces', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST',
        'value' => '{{ project.aaa }}',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    expect($env->is_shared)->toBeTrue();
});

test('is_shared attribute detects variable without spaces', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST',
        'value' => '{{project.aaa}}',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    expect($env->is_shared)->toBeTrue();
});

test('non-shared variable preserves spaces', function () {
    $env = EnvironmentVariable::create([
        'key' => 'REGULAR',
        'value' => 'regular value with spaces',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $env->refresh();
    expect($env->value)->toBe('regular value with spaces');
});

test('mixed content with shared variable preserves all spacing', function () {
    $env = EnvironmentVariable::create([
        'key' => 'MIXED',
        'value' => 'prefix {{ project.aaa }} suffix',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $env->refresh();
    expect($env->value)->toBe('prefix {{ project.aaa }} suffix');
});

test('multiple shared variables preserve individual spacing', function () {
    $env = EnvironmentVariable::create([
        'key' => 'MULTI',
        'value' => '{{ project.a }} and {{team.b}}',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $env->refresh();
    expect($env->value)->toBe('{{ project.a }} and {{team.b}}');
});

test('leading and trailing spaces are trimmed', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TRIMMED',
        'value' => '   {{ project.aaa }}   ',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $env->refresh();
    // External spaces trimmed, internal preserved
    expect($env->value)->toBe('{{ project.aaa }}');
});
