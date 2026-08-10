<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\V5TestSchema;

uses(RefreshDatabase::class);

it('keeps v4 project and environment checks working without the v5 schema', function () {
    config()->set('v5.enabled', false);

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    V5TestSchema::dropAllTables();

    expect($project->isEmpty())->toBeTrue()
        ->and($environment->isEmpty())->toBeTrue();
});
