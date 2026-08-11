<?php

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = $user->teams()->first();
});

it('produces no traffic env when disabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();
    expect(StartSentinel::sentinelTrafficEnvironment($server->fresh()))->toBe([]);
});

it('produces traffic + geoip env when enabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->geoip_maxmind_license_key = 'lic';
    $server->settings->save();

    $env = StartSentinel::sentinelTrafficEnvironment($server->fresh());
    expect($env['TRAFFIC_ENABLED'])->toBe('true');
    expect($env['TRAFFIC_PROXY_TYPE'])->toBe('auto');
    expect($env['GEOIP_ENABLED'])->toBe('true');
    expect($env['GEOIP_MAXMIND_LICENSE_KEY'])->toBe('lic');
    expect($env)->toHaveKey('TRAFFIC_ACCESS_LOG_PATH');
});
