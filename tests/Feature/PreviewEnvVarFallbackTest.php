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

/**
 * Simulate the preview .env generation logic from
 * ApplicationDeploymentJob::generate_runtime_environment_variables()
 * including the production fallback fix.
 */
function simulatePreviewEnvGeneration(Application $application): \Illuminate\Support\Collection
{
    $sorted_environment_variables = $application->environment_variables->sortBy('id');
    $sorted_environment_variables_preview = $application->environment_variables_preview->sortBy('id');

    $envs = collect([]);

    // Preview vars
    $runtime_environment_variables_preview = $sorted_environment_variables_preview->filter(fn ($env) => $env->is_runtime);
    foreach ($runtime_environment_variables_preview as $env) {
        $envs->push($env->key.'='.$env->real_value);
    }

    // Fallback: production vars not overridden by preview,
    // only when preview vars are configured
    if ($runtime_environment_variables_preview->isNotEmpty()) {
        $previewKeys = $runtime_environment_variables_preview->pluck('key')->toArray();
        $fallback_production_vars = $sorted_environment_variables->filter(function ($env) use ($previewKeys) {
            return $env->is_runtime && ! in_array($env->key, $previewKeys);
        });
        foreach ($fallback_production_vars as $env) {
            $envs->push($env->key.'='.$env->real_value);
        }
    }

    return $envs;
}

test('production vars fall back when preview vars exist but do not cover all keys', function () {
    // Create two production vars (booted hook auto-creates preview copies)
    EnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'secret123',
        'is_preview' => false,
        'is_runtime' => true,
        'is_buildtime' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'APP_KEY',
        'value' => 'app_key_value',
        'is_preview' => false,
        'is_runtime' => true,
        'is_buildtime' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Delete only the DB_PASSWORD preview copy — APP_KEY preview copy remains
    $this->application->environment_variables_preview()->where('key', 'DB_PASSWORD')->delete();
    $this->application->refresh();

    // Preview has APP_KEY but not DB_PASSWORD
    expect($this->application->environment_variables_preview()->where('key', 'APP_KEY')->count())->toBe(1);
    expect($this->application->environment_variables_preview()->where('key', 'DB_PASSWORD')->count())->toBe(0);

    $envs = simulatePreviewEnvGeneration($this->application);

    $envString = $envs->implode("\n");
    // DB_PASSWORD should fall back from production
    expect($envString)->toContain('DB_PASSWORD=');
    // APP_KEY should use the preview value
    expect($envString)->toContain('APP_KEY=');
});

test('no fallback when no preview vars are configured at all', function () {
    // Create a production-only var (booted hook auto-creates preview copy)
    EnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'secret123',
        'is_preview' => false,
        'is_runtime' => true,
        'is_buildtime' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Delete ALL preview copies — simulates no preview config
    $this->application->environment_variables_preview()->delete();
    $this->application->refresh();

    expect($this->application->environment_variables_preview()->count())->toBe(0);

    $envs = simulatePreviewEnvGeneration($this->application);

    $envString = $envs->implode("\n");
    // Should NOT fall back to production when no preview vars exist
    expect($envString)->not->toContain('DB_PASSWORD=');
});

test('preview var overrides production var when both exist', function () {
    // Create production var (auto-creates preview copy)
    EnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'prod_password',
        'is_preview' => false,
        'is_runtime' => true,
        'is_buildtime' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Update the auto-created preview copy with a different value
    $this->application->environment_variables_preview()
        ->where('key', 'DB_PASSWORD')
        ->update(['value' => encrypt('preview_password')]);

    $this->application->refresh();
    $envs = simulatePreviewEnvGeneration($this->application);

    // Should contain preview value only, not production
    $envEntries = $envs->filter(fn ($e) => str_starts_with($e, 'DB_PASSWORD='));
    expect($envEntries)->toHaveCount(1);
    expect($envEntries->first())->toContain('preview_password');
});

test('preview-only var works without production counterpart', function () {
    // Create a preview-only var directly (no production counterpart)
    EnvironmentVariable::create([
        'key' => 'PREVIEW_ONLY_VAR',
        'value' => 'preview_value',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $this->application->refresh();
    $envs = simulatePreviewEnvGeneration($this->application);

    $envString = $envs->implode("\n");
    expect($envString)->toContain('PREVIEW_ONLY_VAR=');
});

test('buildtime-only production vars are not included in preview fallback', function () {
    // Create a runtime preview var so fallback is active
    EnvironmentVariable::create([
        'key' => 'SOME_PREVIEW_VAR',
        'value' => 'preview_value',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Create a buildtime-only production var
    EnvironmentVariable::create([
        'key' => 'BUILD_SECRET',
        'value' => 'build_only',
        'is_preview' => false,
        'is_runtime' => false,
        'is_buildtime' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Delete the auto-created preview copy of BUILD_SECRET
    $this->application->environment_variables_preview()->where('key', 'BUILD_SECRET')->delete();
    $this->application->refresh();

    $envs = simulatePreviewEnvGeneration($this->application);

    $envString = $envs->implode("\n");
    expect($envString)->not->toContain('BUILD_SECRET');
    expect($envString)->toContain('SOME_PREVIEW_VAR=');
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
