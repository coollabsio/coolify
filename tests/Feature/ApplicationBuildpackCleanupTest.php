<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Application Model Buildpack Cleanup', function () {
    test('model clears dockerfile fields when build_pack changes from dockerfile to nixpacks', function () {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM node:18\nHEALTHCHECK CMD curl -f http://localhost/ || exit 1',
            'dockerfile_location' => '/Dockerfile',
            'dockerfile_target_build' => 'production',
            'custom_healthcheck_found' => true,
        ]);

        // Change buildpack to nixpacks
        $application->build_pack = 'nixpacks';
        $application->save();

        // Reload from database
        $application->refresh();

        // Verify dockerfile fields were cleared
        expect($application->build_pack)->toBe('nixpacks');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });

    test('model clears dockerfile fields when build_pack changes from dockerfile to static', function () {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM nginx:alpine',
            'dockerfile_location' => '/custom.Dockerfile',
            'dockerfile_target_build' => 'prod',
            'custom_healthcheck_found' => true,
        ]);

        $application->build_pack = 'static';
        $application->save();
        $application->refresh();

        expect($application->build_pack)->toBe('static');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });

    test('model clears dockercompose fields when build_pack changes from dockercompose to nixpacks', function () {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'build_pack' => 'dockercompose',
            'docker_compose_domains' => '{"app": "example.com"}',
            'docker_compose_raw' => 'version: "3.8"\nservices:\n  app:\n    image: nginx',
        ]);

        // Add environment variables that should be deleted
        EnvironmentVariable::create([
            'application_id' => $application->id,
            'key' => 'SERVICE_FQDN_APP',
            'value' => 'app.example.com',
            'is_build_time' => false,
            'is_preview' => false,
        ]);

        EnvironmentVariable::create([
            'application_id' => $application->id,
            'key' => 'SERVICE_URL_APP',
            'value' => 'http://app.example.com',
            'is_build_time' => false,
            'is_preview' => false,
        ]);

        EnvironmentVariable::create([
            'application_id' => $application->id,
            'key' => 'REGULAR_VAR',
            'value' => 'should_remain',
            'is_build_time' => false,
            'is_preview' => false,
        ]);

        $application->build_pack = 'nixpacks';
        $application->save();
        $application->refresh();

        expect($application->build_pack)->toBe('nixpacks');
        expect($application->docker_compose_domains)->toBeNull();
        expect($application->docker_compose_raw)->toBeNull();

        // Verify SERVICE_FQDN_* and SERVICE_URL_* were deleted
        expect($application->environment_variables()->where('key', 'SERVICE_FQDN_APP')->count())->toBe(0);
        expect($application->environment_variables()->where('key', 'SERVICE_URL_APP')->count())->toBe(0);

        // Verify regular variables remain
        expect($application->environment_variables()->where('key', 'REGULAR_VAR')->count())->toBe(1);
    });

    test('model does not clear dockerfile fields when switching to dockerfile', function () {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'build_pack' => 'nixpacks',
            'dockerfile' => null,
        ]);

        $application->build_pack = 'dockerfile';
        $application->save();
        $application->refresh();

        // When switching TO dockerfile, no cleanup should happen
        expect($application->build_pack)->toBe('dockerfile');
    });

    test('model does not clear fields when switching between non-dockerfile buildpacks', function () {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'build_pack' => 'nixpacks',
            'dockerfile' => null,
            'dockerfile_location' => null,
        ]);

        $application->build_pack = 'static';
        $application->save();
        $application->refresh();

        expect($application->build_pack)->toBe('static');
        expect($application->dockerfile)->toBeNull();
    });

    test('model does not trigger cleanup when build_pack is not changed', function () {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM alpine:latest',
            'dockerfile_location' => '/Dockerfile',
            'custom_healthcheck_found' => true,
        ]);

        // Update another field without changing build_pack
        $application->name = 'Updated Name';
        $application->save();
        $application->refresh();

        // Dockerfile fields should remain unchanged
        expect($application->build_pack)->toBe('dockerfile');
        expect($application->dockerfile)->toBe('FROM alpine:latest');
        expect($application->dockerfile_location)->toBe('/Dockerfile');
        expect($application->custom_healthcheck_found)->toBeTrue();
    });
});
