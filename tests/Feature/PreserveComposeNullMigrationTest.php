<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('marks only existing null Compose records without changing values or timestamps', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $composeApp = Application::factory()->create(['environment_id' => $environment->id, 'build_pack' => 'dockercompose']);
    $otherApp = Application::factory()->create(['environment_id' => $environment->id, 'build_pack' => 'nixpacks']);
    $service = Service::factory()->create(['environment_id' => $environment->id]);
    $migration = require database_path('migrations/2026_10_11_183411_add_preserve_compose_null_to_environment_variables.php');
    $migration->down();
    foreach ([$composeApp, $otherApp, $service] as $resource) {
        foreach (['NULL_VALUE' => null, 'EMPTY_VALUE' => '', 'SET_VALUE' => 'configured'] as $key => $value) {
            $resource->environment_variables()->create(['key' => $key, 'value' => $value]);
        }
    }
    $before = EnvironmentVariable::query()->get()->mapWithKeys(fn ($env) => [$env->id => [$env->getRawOriginal('value'), $env->getRawOriginal('updated_at')]]);

    $migration->up();

    foreach (EnvironmentVariable::query()->get() as $env) {
        $expected = $env->key === 'NULL_VALUE' && ($env->resourceable_type === Service::class || $env->resourceable_id === $composeApp->id);
        expect($env->preserve_compose_null)->toBe($expected)
            ->and([$env->getRawOriginal('value'), $env->getRawOriginal('updated_at')])->toBe($before[$env->id]);
    }
    $newEnv = $service->environment_variables()->create(['key' => 'NEW_NULL', 'value' => null]);
    expect($newEnv->fresh()->preserve_compose_null)->toBeFalse();

    $migration->down();
    expect(Schema::hasColumn('environment_variables', 'preserve_compose_null'))->toBeFalse();
    $migration->up();
});
