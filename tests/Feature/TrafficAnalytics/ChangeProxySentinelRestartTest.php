<?php

use App\Actions\Proxy\StartProxy;
use App\Actions\Server\StartSentinel;
use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Server::flushIdentityMap();
    $this->team = User::factory()->create()->teams()->first();
});

afterEach(fn () => Server::flushIdentityMap());

function proxySwitchServer(object $test, string $proxyType, bool $sentinelEnabled, bool $analyticsEnabled): Server
{
    $server = Server::factory()->create(['team_id' => $test->team->id]);
    $server->proxy->set('type', $proxyType);
    $server->save();
    $server->settings->is_sentinel_enabled = $sentinelEnabled;
    $server->settings->is_traffic_analytics_enabled = $analyticsEnabled;
    $server->settings->save();
    Server::flushIdentityMap();

    return $server->fresh();
}

it('restarts Sentinel after a proxy switch when Sentinel and traffic analytics are on', function (string $from, string $to) {
    $server = proxySwitchServer($this, $from, sentinelEnabled: true, analyticsEnabled: true);

    $server->changeProxy($to, async: true);

    StartSentinel::assertPushed(1);
})->with([
    'Traefik to Caddy' => [ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value],
    'Caddy to Traefik' => [ProxyTypes::CADDY->value, ProxyTypes::TRAEFIK->value],
    'Traefik to none' => [ProxyTypes::TRAEFIK->value, ProxyTypes::NONE->value],
    'Caddy to Nginx' => [ProxyTypes::CADDY->value, ProxyTypes::NGINX->value],
]);

it('restarts Sentinel when the Livewire switch selects the proxy synchronously', function () {
    StartProxy::shouldRun();
    $server = proxySwitchServer($this, ProxyTypes::TRAEFIK->value, sentinelEnabled: true, analyticsEnabled: true);

    $server->changeProxy(ProxyTypes::CADDY->value, async: false);

    StartSentinel::assertPushed(1);
});

it('does not restart Sentinel after a proxy switch when nothing relevant is on', function (bool $sentinelEnabled, bool $analyticsEnabled) {
    $server = proxySwitchServer($this, ProxyTypes::TRAEFIK->value, $sentinelEnabled, $analyticsEnabled);

    $server->changeProxy(ProxyTypes::CADDY->value, async: true);

    StartSentinel::assertNotPushed();
    expect((bool) $server->fresh()->settings->is_sentinel_enabled)->toBe($sentinelEnabled);
})->with([
    'analytics off' => [true, false],
    'Sentinel off' => [false, true],
    'both off' => [false, false],
]);

it('does not restart Sentinel when the proxy type does not change', function () {
    $server = proxySwitchServer($this, ProxyTypes::CADDY->value, sentinelEnabled: true, analyticsEnabled: true);

    $server->changeProxy(ProxyTypes::CADDY->value, async: true);

    StartSentinel::assertNotPushed();
});

it('points Sentinel at the Caddy access log after a switch from Traefik', function () {
    $server = proxySwitchServer($this, ProxyTypes::TRAEFIK->value, sentinelEnabled: true, analyticsEnabled: true);
    expect(StartSentinel::trafficLogDirectory($server))->toBe('/data/coolify/proxy');

    $server->changeProxy(ProxyTypes::CADDY->value, async: true);
    $server = $server->fresh();

    expect(StartSentinel::trafficLogDirectory($server))->toBe('/data/coolify/proxy/caddy')
        ->and(StartSentinel::sentinelTrafficEnvironment($server)['TRAFFIC_ACCESS_LOG_PATH'])
        ->toBe('/data/coolify/proxy/caddy/access.log');
});

it('gives Sentinel no traffic environment after a switch to a proxy without analytics support', function () {
    $server = proxySwitchServer($this, ProxyTypes::TRAEFIK->value, sentinelEnabled: true, analyticsEnabled: true);

    $server->changeProxy(ProxyTypes::NONE->value, async: true);

    expect(StartSentinel::sentinelTrafficEnvironment($server->fresh()))->toBe([]);
});
