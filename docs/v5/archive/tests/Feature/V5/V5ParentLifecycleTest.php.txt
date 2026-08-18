<?php

use App\Models\V5\Application as V5Application;
use App\Models\V5\ResourceConnection;

beforeEach(function () {
    resetV5DashboardTestState();
    createSharedUserAndTeamTables();
});

it('does not consider projects or environments with v5 applications empty', function () {
    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');

    V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx',
        'image' => 'nginx:alpine',
        'container_name' => 'v5-nginx',
    ]);

    expect($project->isEmpty())->toBeFalse()
        ->and($environment->isEmpty())->toBeFalse();
});

it('does not consider projects or environments with v5 resource connections empty', function () {
    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');

    ResourceConnection::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'resource_one_type' => V5Application::class,
        'resource_one_id' => 1,
        'resource_two_type' => V5Application::class,
        'resource_two_id' => 2,
        'resource_pair_key' => 'application:1|application:2',
        'created_by_user_id' => $user->id,
    ]);

    expect($project->isEmpty())->toBeFalse()
        ->and($environment->isEmpty())->toBeFalse();
});
