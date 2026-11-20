<?php

use App\Actions\Shared\ResolveResourcePlacement;
use App\Exceptions\AmbiguousDestinationException;
use App\Exceptions\DestinationNotOnServerException;
use App\Exceptions\NoDestinationsException;
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

it('throws NoDestinationsException when the server has no destinations', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->standaloneDockers()->delete();

    ResolveResourcePlacement::run($team->id, $project->uuid, 'production', null, $server->uuid, null);
})->throws(NoDestinationsException::class);

it('throws DestinationNotOnServerException when destination_uuid is not on the server', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-2']);

    ResolveResourcePlacement::run($team->id, $project->uuid, 'production', null, $server->uuid, 'not-a-destination');
})->throws(DestinationNotOnServerException::class);

it('ignores destination_uuid when the server has exactly one destination', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $placement = ResolveResourcePlacement::run($team->id, $project->uuid, 'production', null, $server->uuid, 'bogus');

    expect($placement->destination->id)->toBe($server->destinations()->first()->id);
});
