<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = $user->teams()->first();
});

it('defaults traffic analytics to enabled for a normal server and exposes a server helper', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    expect($server->settings->is_traffic_analytics_enabled)->toBeTrue();
    expect($server->isTrafficAnalyticsEnabled())->toBeTrue();

    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();
    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
});

it('defaults traffic analytics to disabled for a swarm server', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $setting = ServerSetting::create([
        'server_id' => $server->id,
        'is_swarm_manager' => true,
    ]);

    expect($setting->is_traffic_analytics_enabled)->toBeFalse();
});

it('defaults traffic analytics to disabled for a build server', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $setting = ServerSetting::create([
        'server_id' => $server->id,
        'is_build_server' => true,
    ]);

    expect($setting->is_traffic_analytics_enabled)->toBeFalse();
});

it('respects an explicit traffic analytics value on creation', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $setting = ServerSetting::create([
        'server_id' => $server->id,
        'is_traffic_analytics_enabled' => false,
    ]);

    expect($setting->is_traffic_analytics_enabled)->toBeFalse();
});

it('encrypts the maxmind license key and hides it from array output', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->geoip_maxmind_license_key = 'secret-key';
    $server->settings->save();

    expect($server->settings->fresh()->geoip_maxmind_license_key)->toBe('secret-key');
    expect(array_key_exists('geoip_maxmind_license_key', $server->settings->fresh()->toArray()))->toBeFalse();
});
