<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceDatabase;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates ServiceDatabase records for detected databases in Application compose path', function () {
    $team = Team::factory()->create();

    $project = Project::factory()->create(['team_id' => $team->id]);

    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
    ]);

    ServiceDatabase::create([
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

    $project = Project::factory()->create(['team_id' => $team->id]);

    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
    ]);

    $serviceDatabase = ServiceDatabase::create([
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
