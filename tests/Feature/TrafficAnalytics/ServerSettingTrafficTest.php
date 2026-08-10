<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = $user->teams()->first();
});

it('defaults traffic analytics to disabled and exposes a server helper', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    expect($server->settings->is_traffic_analytics_enabled)->toBeFalse();
    expect($server->isTrafficAnalyticsEnabled())->toBeFalse();

    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();
    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue();
});

it('encrypts the maxmind license key and hides it from array output', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->geoip_maxmind_license_key = 'secret-key';
    $server->settings->save();

    expect($server->settings->fresh()->geoip_maxmind_license_key)->toBe('secret-key');
    expect(array_key_exists('geoip_maxmind_license_key', $server->settings->fresh()->toArray()))->toBeFalse();
});
