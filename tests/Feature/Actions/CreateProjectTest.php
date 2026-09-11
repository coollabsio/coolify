<?php

use App\Actions\Project\CreateEnvironment;
use App\Actions\Project\CreateProject;
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
})->throws(RuntimeException::class);
