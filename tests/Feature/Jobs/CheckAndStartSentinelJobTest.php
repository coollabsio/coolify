<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('does not start Sentinel after it has been disabled', function () {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
    ]);
    $server->settings->update([
        'is_metrics_enabled' => false,
        'is_sentinel_enabled' => false,
    ]);

    (new CheckAndStartSentinelJob($server))->handle();

    expect((bool) $server->settings->fresh()->is_sentinel_enabled)->toBeFalse();
});
