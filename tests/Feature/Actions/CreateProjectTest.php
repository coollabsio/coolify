<?php

use App\Actions\Project\CreateEnvironment;
use App\Actions\Project\CreateProject;
use App\Exceptions\EnvironmentAlreadyExistsException;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a project with a default production environment', function () {
    $team = Team::factory()->create();
    $project = CreateProject::run($team->id, 'apps', 'my apps');

    expect($project->team_id)->toBe($team->id)
        ->and($project->description)->toBe('my apps')
        ->and($project->environments()->where('name', 'production')->exists())->toBeTrue();
});

it('creates an additional environment and rejects duplicates', function () {
    $team = Team::factory()->create();
    $project = CreateProject::run($team->id, 'apps');

    $env = CreateEnvironment::run($project, 'staging');
    expect($env->name)->toBe('staging');

    CreateEnvironment::run($project, 'staging');
})->throws(EnvironmentAlreadyExistsException::class);

it('throws EnvironmentAlreadyExistsException for a duplicate environment name', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);

    CreateEnvironment::run($project, 'production');
})->throws(EnvironmentAlreadyExistsException::class);
