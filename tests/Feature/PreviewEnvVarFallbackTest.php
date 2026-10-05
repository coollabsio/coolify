<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    $this->actingAs($this->user);
});

test('preview env var inherits is_runtime and is_buildtime from production var', function () {
    // Create production var WITH explicit flags
    EnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'secret123',
        'is_preview' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $preview = EnvironmentVariable::where('key', 'DB_PASSWORD')
        ->where('is_preview', true)
        ->where('resourceable_id', $this->application->id)
        ->first();

    expect($preview)->not->toBeNull();
    expect($preview->is_runtime)->toBeTrue();
    expect($preview->is_buildtime)->toBeTrue();
});

test('preview env var gets correct defaults when production var created without explicit flags', function () {
    // Simulate code paths (docker-compose parser, dev view bulk submit) that create
    // env vars without explicitly setting is_runtime/is_buildtime
    EnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'secret123',
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $preview = EnvironmentVariable::where('key', 'DB_PASSWORD')
        ->where('is_preview', true)
        ->where('resourceable_id', $this->application->id)
        ->first();

    expect($preview)->not->toBeNull();
    expect($preview->is_runtime)->toBeTrue();
    expect($preview->is_buildtime)->toBeTrue();
});
