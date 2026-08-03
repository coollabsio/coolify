<?php

use App\Models\V5\Application as V5Application;
use App\Models\V5\ResourceConnection;

beforeEach(function () {
    resetV5DashboardTestState();
    createSharedUserAndTeamTables();
});

it('prevents team members from creating v5 applications', function () {
    [$user, $team] = createV5UserWithTeam();
    $user->teams()->updateExistingPivot($team->id, ['role' => 'member']);

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/applications/nginx')
        ->assertForbidden();

    expect(V5Application::query()->count())->toBe(0);
});

it('prevents team members from creating v5 resource connections', function () {
    [$user, $team] = createV5UserWithTeam();
    $user->teams()->updateExistingPivot($team->id, ['role' => 'member']);

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/resource-connections')
        ->assertForbidden();

    expect(ResourceConnection::query()->count())->toBe(0);
});
