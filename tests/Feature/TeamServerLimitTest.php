<?php

use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', true);
});

it('returns server limit when team is passed directly without session', function () {
    $team = Team::factory()->create();

    $limit = Team::serverLimit($team);

    // self_hosted returns 999999999999
    expect($limit)->toBe(999999999999);
});

it('returns 0 when no team is provided and no session exists', function () {
    $limit = Team::serverLimit();

    expect($limit)->toBe(0);
});

it('returns true for serverLimitReached when no team and no session', function () {
    $result = Team::serverLimitReached();

    expect($result)->toBeTrue();
});

it('returns false for serverLimitReached when team has servers under limit', function () {
    $team = Team::factory()->create();
    Server::factory()->create(['team_id' => $team->id]);

    $result = Team::serverLimitReached($team);

    // self_hosted has very high limit, 1 server is well under
    expect($result)->toBeFalse();
});

it('returns true for serverLimitReached when team has servers at limit', function () {
    config()->set('constants.coolify.self_hosted', false);

    $team = Team::factory()->create(['custom_server_limit' => 1]);
    Server::factory()->create(['team_id' => $team->id]);

    $result = Team::serverLimitReached($team);

    expect($result)->toBeTrue();
});
