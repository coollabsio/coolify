<?php

use App\Actions\Shared\ResolveResourcePlacement;
use App\Exceptions\AmbiguousDestinationException;
use App\Exceptions\ProjectNotFoundException;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves project, environment, server and single destination', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]); // auto-creates "production" env
    $env = $project->environments()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);   // booted() auto-creates one destination
    $destination = $server->destinations()->first();

    $placement = ResolveResourcePlacement::run(
        $team->id, $project->uuid, 'production', null, $server->uuid, null,
    );

    expect($placement->project->id)->toBe($project->id)
        ->and($placement->environment->id)->toBe($env->id)
        ->and($placement->server->id)->toBe($server->id)
        ->and($placement->destination->id)->toBe($destination->id);
});

it('throws when the project is not in the team', function () {
    $team = Team::factory()->create();
    Server::factory()->create(['team_id' => $team->id]);

    ResolveResourcePlacement::run($team->id, 'missing-uuid', 'production', null, 'srv-x', null);
})->throws(ProjectNotFoundException::class);

it('requires destination_uuid when the server has multiple destinations', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]); // already has one destination
    StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-2']); // second destination

    ResolveResourcePlacement::run($team->id, $project->uuid, 'production', null, $server->uuid, null);
})->throws(AmbiguousDestinationException::class);
