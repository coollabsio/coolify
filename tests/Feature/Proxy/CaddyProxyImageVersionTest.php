<?php

use App\Enums\ProxyTypes;
use App\Livewire\Server\Proxy;
use App\Livewire\Server\TrafficAnalyticsSettings;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

afterEach(fn () => Server::flushIdentityMap());

function caddyImageProxy(?string $image, array $overrides = []): array
{
    return array_merge([
        'type' => ProxyTypes::CADDY->value,
        'status' => 'running',
        'last_saved_settings' => 'applied',
        'last_applied_settings' => 'applied',
        'last_saved_proxy_configuration' => $image === null ? null : "services:\n  caddy:\n    image: '{$image}'\n",
    ], $overrides);
}

it('parses the caddy-docker-proxy version from the image', function (?string $image, ?array $expected) {
    expect(Server::caddyDockerProxyImageVersion($image))->toBe($expected);
})->with([
    'old default 2.8-alpine' => ['lucaslorentz/caddy-docker-proxy:2.8-alpine', [2, 8]],
    '2.9' => ['lucaslorentz/caddy-docker-proxy:2.9', [2, 9]],
    'current default 2.13-alpine' => ['lucaslorentz/caddy-docker-proxy:2.13-alpine', [2, 13]],
    'registry prefix and patch version' => ['docker.io/lucaslorentz/caddy-docker-proxy:2.11.4-alpine', [2, 11]],
    'latest tag' => ['lucaslorentz/caddy-docker-proxy:latest', null],
    'no tag' => ['lucaslorentz/caddy-docker-proxy', null],
    'digest' => ['lucaslorentz/caddy-docker-proxy@sha256:0a3f8e2b1c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f', null],
    'custom image' => ['caddy:2.11', null],
    'custom image with a similar name' => ['example/my-caddy-docker-proxy:2.8', null],
    'no image' => [null, null],
]);

it('reports an outdated Caddy image only for caddy-docker-proxy older than 2.9', function (?string $image, ?string $expected) {
    $server = Server::factory()->make(['proxy' => caddyImageProxy($image)]);

    expect($server->outdatedCaddyProxyImage())->toBe($expected);
})->with([
    'old default 2.8-alpine' => ['lucaslorentz/caddy-docker-proxy:2.8-alpine', 'lucaslorentz/caddy-docker-proxy:2.8-alpine'],
    '2.7' => ['lucaslorentz/caddy-docker-proxy:2.7', 'lucaslorentz/caddy-docker-proxy:2.7'],
    '2.9' => ['lucaslorentz/caddy-docker-proxy:2.9', null],
    'current default 2.13-alpine' => ['lucaslorentz/caddy-docker-proxy:2.13-alpine', null],
    'latest tag' => ['lucaslorentz/caddy-docker-proxy:latest', null],
    'digest' => ['lucaslorentz/caddy-docker-proxy@sha256:0a3f8e2b1c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f', null],
    'custom image' => ['caddy:2.7', null],
    'no saved configuration' => [null, null],
]);

it('does not report an outdated Caddy image for other proxies or invalid YAML', function () {
    $traefik = Server::factory()->make(['proxy' => caddyImageProxy('lucaslorentz/caddy-docker-proxy:2.8-alpine', ['type' => ProxyTypes::TRAEFIK->value])]);
    $invalid = Server::factory()->make(['proxy' => caddyImageProxy(null, ['last_saved_proxy_configuration' => "services: [\n"])]);

    expect($traefik->outdatedCaddyProxyImage())->toBeNull()
        ->and($invalid->outdatedCaddyProxyImage())->toBeNull();
});

it('reports the saved image as outdated also while the change is not applied', function () {
    $server = Server::factory()->make(['proxy' => caddyImageProxy('lucaslorentz/caddy-docker-proxy:2.8-alpine', ['last_saved_settings' => 'new'])]);

    expect($server->outdatedCaddyProxyImage())->toBe('lucaslorentz/caddy-docker-proxy:2.8-alpine')
        ->and($server->caddySupportsLogAppend())->toBeFalse();
});

it('uses the recommended Caddy image as the default proxy image', function () {
    expect(Server::RECOMMENDED_CADDY_PROXY_IMAGE)->toBe('lucaslorentz/caddy-docker-proxy:2.13-alpine')
        ->and(Server::caddyDockerProxyImageVersion(Server::RECOMMENDED_CADDY_PROXY_IMAGE))->toBe([2, 13]);
});

it('shows the outdated Caddy image warning on the proxy page', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => caddyImageProxy('lucaslorentz/caddy-docker-proxy:2.8-alpine'),
    ]);

    Livewire::test(Proxy::class, ['server' => $server])
        ->assertSee('Caddy proxy image is outdated')
        ->assertSee('lucaslorentz/caddy-docker-proxy:2.8-alpine')
        ->assertSee('2.9 or newer')
        ->assertSee('lucaslorentz/caddy-docker-proxy:2.13-alpine')
        ->assertSee('restart the proxy');
});

it('hides the outdated Caddy image warning on the proxy page', function (string $type, string $image) {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => caddyImageProxy($image, ['type' => $type]),
    ]);

    Livewire::test(Proxy::class, ['server' => $server])
        ->assertDontSee('Caddy proxy image is outdated');
})->with([
    'current Caddy image' => [ProxyTypes::CADDY->value, 'lucaslorentz/caddy-docker-proxy:2.13-alpine'],
    'Caddy latest tag' => [ProxyTypes::CADDY->value, 'lucaslorentz/caddy-docker-proxy:latest'],
    'custom Caddy image' => [ProxyTypes::CADDY->value, 'caddy:2.7'],
    'Traefik' => [ProxyTypes::TRAEFIK->value, 'lucaslorentz/caddy-docker-proxy:2.8-alpine'],
]);

it('shows the outdated Caddy image warning on the traffic analytics settings', function (bool $analyticsEnabled) {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => caddyImageProxy('lucaslorentz/caddy-docker-proxy:2.8-alpine'),
    ]);
    $server->settings->update(['is_traffic_analytics_enabled' => $analyticsEnabled]);

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $server->fresh()])
        ->assertSee('Caddy proxy image is outdated')
        ->assertSee('lucaslorentz/caddy-docker-proxy:2.8-alpine')
        ->assertSee('lucaslorentz/caddy-docker-proxy:2.13-alpine');
})->with([
    'analytics enabled' => [true],
    'analytics disabled' => [false],
]);

it('hides the outdated Caddy image warning on the traffic analytics settings', function (string $type, string $image) {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => caddyImageProxy($image, ['type' => $type]),
    ]);
    $server->settings->update(['is_traffic_analytics_enabled' => true]);

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $server->fresh()])
        ->assertDontSee('Caddy proxy image is outdated');
})->with([
    'current Caddy image' => [ProxyTypes::CADDY->value, 'lucaslorentz/caddy-docker-proxy:2.13-alpine'],
    'custom Caddy image' => [ProxyTypes::CADDY->value, 'example/caddy:1.0'],
    'Traefik' => [ProxyTypes::TRAEFIK->value, 'lucaslorentz/caddy-docker-proxy:2.8-alpine'],
]);
