<?php

use App\Jobs\ServerLimitCheckJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);

    Notification::fake();

    $this->team = Team::factory()->create(['custom_server_limit' => 5]);
});

function createServerForTeam(Team $team, bool $forceDisabled = false): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    if ($forceDisabled) {
        $server->settings()->update(['force_disabled' => true]);
    }

    return $server->fresh(['settings']);
}

it('re-enables force-disabled servers when under the limit', function () {
    createServerForTeam($this->team);
    $server2 = createServerForTeam($this->team, forceDisabled: true);
    $server3 = createServerForTeam($this->team, forceDisabled: true);

    expect($server2->settings->force_disabled)->toBeTruthy();
    expect($server3->settings->force_disabled)->toBeTruthy();

    // 3 servers, limit 5 → all should be re-enabled
    ServerLimitCheckJob::dispatchSync($this->team);

    expect($server2->fresh()->settings->force_disabled)->toBeFalsy();
    expect($server3->fresh()->settings->force_disabled)->toBeFalsy();
});

it('re-enables force-disabled servers when exactly at the limit', function () {
    $this->team->update(['custom_server_limit' => 3]);

    createServerForTeam($this->team);
    createServerForTeam($this->team);
    $server3 = createServerForTeam($this->team, forceDisabled: true);

    // 3 servers, limit 3 → disabled one should be re-enabled
    ServerLimitCheckJob::dispatchSync($this->team);

    expect($server3->fresh()->settings->force_disabled)->toBeFalsy();
});

it('disables newest servers when over the limit', function () {
    $this->team->update(['custom_server_limit' => 2]);

    $oldest = createServerForTeam($this->team);
    sleep(1);
    $middle = createServerForTeam($this->team);
    sleep(1);
    $newest = createServerForTeam($this->team);

    // 3 servers, limit 2 → newest 1 should be disabled
    ServerLimitCheckJob::dispatchSync($this->team);

    expect($oldest->fresh()->settings->force_disabled)->toBeFalsy();
    expect($middle->fresh()->settings->force_disabled)->toBeFalsy();
    expect($newest->fresh()->settings->force_disabled)->toBeTruthy();
});

it('does not change servers when under limit and none are force-disabled', function () {
    $server1 = createServerForTeam($this->team);
    $server2 = createServerForTeam($this->team);

    // 2 servers, limit 5 → nothing to do
    ServerLimitCheckJob::dispatchSync($this->team);

    expect($server1->fresh()->settings->force_disabled)->toBeFalsy();
    expect($server2->fresh()->settings->force_disabled)->toBeFalsy();
});
