<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Application Railpack Support', function () {
    beforeEach(function () {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    });

    test('could_set_build_commands returns true for railpack', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'railpack',
        ]);

        expect($application->could_set_build_commands())->toBeTrue();
    });

    test('could_set_build_commands returns true for nixpacks', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'nixpacks',
        ]);

        expect($application->could_set_build_commands())->toBeTrue();
    });

    test('could_set_build_commands returns false for dockerfile', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'dockerfile',
        ]);

        expect($application->could_set_build_commands())->toBeFalse();
    });

    test('railpack_environment_variables returns only RAILPACK_ prefixed vars', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'railpack',
        ]);

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'key' => 'RAILPACK_NODE_VERSION',
            'value' => '20',
            'is_buildtime' => true,
            'is_preview' => false,
        ]);

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'key' => 'REGULAR_VAR',
            'value' => 'value',
            'is_buildtime' => false,
            'is_preview' => false,
        ]);

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'key' => 'NIXPACKS_NODE_VERSION',
            'value' => '18',
            'is_buildtime' => true,
            'is_preview' => false,
        ]);

        $railpackVars = $application->railpack_environment_variables;
        expect($railpackVars)->toHaveCount(1);
        expect($railpackVars->first()->key)->toBe('RAILPACK_NODE_VERSION');
    });

    test('runtime_environment_variables excludes RAILPACK_ and NIXPACKS_ prefixed vars', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'railpack',
        ]);

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'key' => 'RAILPACK_NODE_VERSION',
            'value' => '20',
            'is_buildtime' => true,
            'is_preview' => false,
        ]);

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'key' => 'NIXPACKS_NODE_VERSION',
            'value' => '18',
            'is_buildtime' => true,
            'is_preview' => false,
        ]);

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'key' => 'APP_ENV',
            'value' => 'production',
            'is_buildtime' => false,
            'is_preview' => false,
        ]);

        $runtimeVars = $application->runtime_environment_variables;
        expect($runtimeVars)->toHaveCount(1);
        expect($runtimeVars->first()->key)->toBe('APP_ENV');
    });

    test('railpack_environment_variables_preview returns only RAILPACK_ prefixed preview vars', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'railpack',
        ]);

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'key' => 'RAILPACK_BUILD_CMD',
            'value' => 'npm run build',
            'is_buildtime' => true,
            'is_preview' => true,
        ]);

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'key' => 'REGULAR_VAR',
            'value' => 'value',
            'is_buildtime' => false,
            'is_preview' => true,
        ]);

        $previewVars = $application->railpack_environment_variables_preview;
        expect($previewVars)->toHaveCount(1);
        expect($previewVars->first()->key)->toBe('RAILPACK_BUILD_CMD');
    });
});
