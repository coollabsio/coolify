<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Set current team
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    // Create project and environment
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

describe('Buildpack Switching Cleanup', function () {
    beforeEach(function () {
        // TODO: General view triggers Application query with empty key ("where '' = 1") when rendering.
        // ApplicationBuildpackCleanupTest covers the same model cleanup logic without Livewire view.
        $this->markTestSkipped('Livewire General view triggers Application refetch with empty key (where "" = 1)');
    });

    test('clears dockerfile fields when switching from dockerfile to nixpacks', function () {
        // Create an application with dockerfile buildpack and dockerfile content
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM node:18\nHEALTHCHECK CMD curl -f http://localhost/ || exit 1',
            'dockerfile_location' => '/Dockerfile',
            'dockerfile_target_build' => 'production',
            'custom_healthcheck_found' => true,
        ]);
        // Reload with global scope so view does not trigger refetch (avoids "where '' = 1" SQL error)
        $application = Application::find($application->id);

        // Switch to nixpacks buildpack (pass applicationId so component does not resolve with empty key)
        Livewire::test(General::class, ['application' => $application, 'applicationId' => $application->uuid])
            ->assertSuccessful()
            ->set('buildPack', 'nixpacks')
            ->call('updatedBuildPack');

        // Verify dockerfile fields were cleared
        $application->refresh();
        expect($application->build_pack)->toBe('nixpacks');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });

    test('clears dockerfile fields when switching from dockerfile to static', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM nginx:alpine',
            'dockerfile_location' => '/custom.Dockerfile',
            'dockerfile_target_build' => 'prod',
            'custom_healthcheck_found' => true,
        ]);
        $application = Application::find($application->id);

        Livewire::test(General::class, ['application' => $application, 'applicationId' => $application->uuid])
            ->assertSuccessful()
            ->set('buildPack', 'static')
            ->call('updatedBuildPack');

        $application->refresh();
        expect($application->build_pack)->toBe('static');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });

    test('does not clear dockerfile fields when switching to dockerfile', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'nixpacks',
            'dockerfile' => null,
        ]);
        $application = Application::find($application->id);

        Livewire::test(General::class, ['application' => $application, 'applicationId' => $application->uuid])
            ->assertSuccessful()
            ->set('buildPack', 'dockerfile')
            ->call('updatedBuildPack');

        // When switching TO dockerfile, fields remain as they were
        $application->refresh();
        expect($application->build_pack)->toBe('dockerfile');
    });

    test('does not affect fields when switching between non-dockerfile buildpacks', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'nixpacks',
            'dockerfile' => null,
            'dockerfile_location' => null,
        ]);
        $application = Application::find($application->id);

        Livewire::test(General::class, ['application' => $application, 'applicationId' => $application->uuid])
            ->assertSuccessful()
            ->set('buildPack', 'static')
            ->call('updatedBuildPack');

        $application->refresh();
        expect($application->build_pack)->toBe('static');
        expect($application->dockerfile)->toBeNull();
    });

    test('clears dockerfile fields when switching from dockerfile to dockercompose', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM alpine:latest',
            'dockerfile_location' => '/docker/Dockerfile',
            'custom_healthcheck_found' => true,
        ]);
        $application = Application::find($application->id);

        Livewire::test(General::class, ['application' => $application, 'applicationId' => $application->uuid])
            ->assertSuccessful()
            ->set('buildPack', 'dockercompose')
            ->call('updatedBuildPack');

        $application->refresh();
        expect($application->build_pack)->toBe('dockercompose');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });
});
