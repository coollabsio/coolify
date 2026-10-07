<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
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

    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'coolify-test']);
});

describe('Buildpack Switching Cleanup', function () {
    test('clears dockerfile fields when switching from dockerfile to nixpacks', function () {
        // Create an application with dockerfile buildpack and dockerfile content
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM node:18\nHEALTHCHECK CMD curl -f http://localhost/ || exit 1',
            'dockerfile_location' => '/Dockerfile',
            'dockerfile_target_build' => 'production',
            'custom_healthcheck_found' => true,
        ]);

        // Switch to nixpacks buildpack
        Livewire::test(General::class, ['application' => $application->fresh()])
            ->assertSuccessful()
            ->set('buildPack', 'nixpacks');

        // Verify dockerfile fields were cleared
        $application->refresh();
        expect($application->build_pack)->toBe('nixpacks');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect((bool) $application->custom_healthcheck_found)->toBeFalse();
    });

    test('clears dockerfile fields when switching from dockerfile to static', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM nginx:alpine',
            'dockerfile_location' => '/custom.Dockerfile',
            'dockerfile_target_build' => 'prod',
            'custom_healthcheck_found' => true,
        ]);

        Livewire::test(General::class, ['application' => $application->fresh()])
            ->assertSuccessful()
            ->set('buildPack', 'static');

        $application->refresh();
        expect($application->build_pack)->toBe('static');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect((bool) $application->custom_healthcheck_found)->toBeFalse();
    });

    test('does not clear dockerfile fields when switching to dockerfile', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'nixpacks',
            'dockerfile' => null,
        ]);

        Livewire::test(General::class, ['application' => $application->fresh()])
            ->assertSuccessful()
            ->set('buildPack', 'dockerfile');

        // When switching TO dockerfile, fields remain as they were
        $application->refresh();
        expect($application->build_pack)->toBe('dockerfile');
    });

    test('does not affect fields when switching between non-dockerfile buildpacks', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'nixpacks',
            'dockerfile' => null,
            'dockerfile_location' => null,
        ]);

        Livewire::test(General::class, ['application' => $application->fresh()])
            ->assertSuccessful()
            ->set('buildPack', 'static');

        $application->refresh();
        expect($application->build_pack)->toBe('static');
        expect($application->dockerfile)->toBeNull();
    });

    test('clears dockerfile fields when switching from dockerfile to railpack', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM node:18',
            'dockerfile_location' => '/Dockerfile',
            'dockerfile_target_build' => 'production',
            'custom_healthcheck_found' => true,
        ]);

        Livewire::test(General::class, ['application' => $application->fresh()])
            ->assertSuccessful()
            ->set('buildPack', 'railpack');

        $application->refresh();
        expect($application->build_pack)->toBe('railpack');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect((bool) $application->custom_healthcheck_found)->toBeFalse();
    });

    test('clears dockerfile fields when switching from dockerfile to dockercompose', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM alpine:latest',
            'dockerfile_location' => '/docker/Dockerfile',
            'custom_healthcheck_found' => true,
        ]);

        Livewire::test(General::class, ['application' => $application->fresh()])
            ->assertSuccessful()
            ->set('buildPack', 'dockercompose');

        $application->refresh();
        expect($application->build_pack)->toBe('dockercompose');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect((bool) $application->custom_healthcheck_found)->toBeFalse();
    });
});
