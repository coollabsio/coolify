<?php

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
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
    expect($env['TRAFFIC_TOPN'])->toBe('50');
    expect($env['TRAFFIC_SAMPLE_THRESHOLD'])->toBe('0');
    expect($env['TRAFFIC_RETENTION_1H_DAYS'])->toBe('30');
    expect($env['TRAFFIC_RETENTION_1D_DAYS'])->toBe('395');
    expect($env['GEOIP_ENABLED'])->toBe('true');
    expect($env['GEOIP_REFRESH_DAYS'])->toBe('30');
    expect($env['GEOIP_MAXMIND_LICENSE_KEY'])->toBe('lic');
    expect($env)->toHaveKey('TRAFFIC_ACCESS_LOG_PATH');
});

it('passes custom traffic settings as sentinel env', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->traffic_topn = 100;
    $server->settings->traffic_sample_threshold = 500;
    $server->settings->traffic_retention_1h_days = 14;
    $server->settings->traffic_retention_1d_days = 180;
    $server->settings->is_geoip_enabled = false;
    $server->settings->geoip_refresh_days = 7;
    $server->settings->save();

    $env = StartSentinel::sentinelTrafficEnvironment($server->fresh());
    expect($env['TRAFFIC_TOPN'])->toBe('100');
    expect($env['TRAFFIC_SAMPLE_THRESHOLD'])->toBe('500');
    expect($env['TRAFFIC_RETENTION_1H_DAYS'])->toBe('14');
    expect($env['TRAFFIC_RETENTION_1D_DAYS'])->toBe('180');
    expect($env['GEOIP_ENABLED'])->toBe('false');
    expect($env['GEOIP_REFRESH_DAYS'])->toBe('7');
    expect($env)->not->toHaveKey('GEOIP_MAXMIND_LICENSE_KEY');
});
