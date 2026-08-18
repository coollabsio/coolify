<?php

use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('databases includes different database types with the same primary key', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->firstOrFail();

    StandaloneRedis::forceCreate([
        'id' => 42,
        'name' => 'redis',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    StandaloneMariadb::forceCreate([
        'id' => 42,
        'name' => 'mariadb',
        'mariadb_root_password' => 'password',
        'mariadb_password' => 'password',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    expect($project->redis()->count())->toBe(1)
        ->and($project->mariadbs()->count())->toBe(1)
        ->and($project->databases())->toHaveCount(2);
});
