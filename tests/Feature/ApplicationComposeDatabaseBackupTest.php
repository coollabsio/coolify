<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceDatabase;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates ServiceDatabase records for detected databases in Application compose path', function () {
    $team = Team::factory()->create();

    $project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $team->id,
    ]);

    $environment = Environment::create([
        'name' => 'test-env-'.Str::random(8),
        'project_id' => $project->id,
    ]);

    $application = Application::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'test-app',
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => 1,
        'destination_type' => 'App\Models\StandaloneDocker',
    ]);

    ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'postgres',
        'image' => 'postgres:16',
        'application_id' => $application->id,
    ]);

    $serviceDatabase = $application->serviceDatabase()->first();

    expect($serviceDatabase)->not->toBeNull()
        ->and($serviceDatabase->name)->toBe('postgres')
        ->and($serviceDatabase->application_id)->toBe($application->id)
        ->and($serviceDatabase->service_id)->toBeNull();
});

it('returns the correct team for ServiceDatabase through the application relationship chain', function () {
    $team = Team::factory()->create();

    $project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $team->id,
    ]);

    $environment = Environment::create([
        'name' => 'test-env-'.Str::random(8),
        'project_id' => $project->id,
    ]);

    $application = Application::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'test-app',
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => 1,
        'destination_type' => 'App\Models\StandaloneDocker',
    ]);

    $serviceDatabase = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'postgres',
        'image' => 'postgres:16',
        'application_id' => $application->id,
    ]);

    expect($serviceDatabase->team())->not->toBeNull()
        ->and($serviceDatabase->team()->id)->toBe($team->id);
});

it('isBackupSolutionAvailable returns true for postgres image', function () {
    $serviceDatabase = new ServiceDatabase([
        'image' => 'postgres:16',
    ]);

    expect($serviceDatabase->isBackupSolutionAvailable())->toBeTrue();
});

it('isBackupSolutionAvailable returns false for non-database image', function () {
    $serviceDatabase = new ServiceDatabase([
        'image' => 'nginx:latest',
    ]);

    expect($serviceDatabase->isBackupSolutionAvailable())->toBeFalse();
});
