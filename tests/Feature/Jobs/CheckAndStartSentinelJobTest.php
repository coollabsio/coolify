<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('treats Sentinel as enabled for regular servers even when the legacy flag and metrics are disabled', function () {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
    ]);
    $server->settings->update([
        'is_metrics_enabled' => false,
        'is_sentinel_enabled' => false,
    ]);

    expect($server->fresh()->isSentinelEnabled())->toBeTrue();
});

it('does not enable Sentinel for excluded server types', function (array $settings, array $metadata = []) {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
        'server_metadata' => $metadata,
    ]);
    $server->settings->update(array_merge([
        'is_metrics_enabled' => false,
        'is_sentinel_enabled' => true,
    ], $settings));

    expect($server->fresh()->isSentinelEnabled())->toBeFalse();
})->with([
    'build server' => [['is_build_server' => true]],
    'swarm manager' => [['is_swarm_manager' => true]],
    'swarm worker' => [['is_swarm_worker' => true]],
    'transferred server' => [[], ['transfer' => ['status' => 'transferred']]],
    'force-disabled server' => [['force_disabled' => true]],
]);

it('keeps metrics optional while Sentinel remains enabled', function () {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
    ]);
    $server->settings->update(['is_metrics_enabled' => false]);

    expect($server->fresh()->isSentinelEnabled())->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_metrics_enabled)->toBeFalse();
});
