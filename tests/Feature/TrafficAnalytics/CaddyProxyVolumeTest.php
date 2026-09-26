<?php

use App\Actions\Server\StartSentinel;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    // generateDefaultProxyConfiguration() synchronously persists the config to the
    // server over SSH (SaveProxyConfiguration); fake the process layer so tests
    // don't attempt a real SSH connection.
    Process::fake();

    $user = User::factory()->create();
    $this->team = $user->teams()->first();

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

it('does not mount the traffic volume for caddy when traffic analytics is disabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $server->proxy->set('type', 'CADDY');
    $server->save();
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    $config = Yaml::parse(generateDefaultProxyConfiguration($server->fresh()));

    expect($config['services']['caddy']['volumes'])
        ->not->toContain($server->proxyPath().':/traffic');
});

it('mounts the traffic volume for caddy when traffic analytics is enabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $server->proxy->set('type', 'CADDY');
    $server->save();
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    $config = Yaml::parse(generateDefaultProxyConfiguration($server->fresh()));

    expect($config['services']['caddy']['volumes'])
        ->toContain($server->proxyPath().':/traffic');
});

it('uses a default caddy image that supports per-app traffic attribution', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $server->proxy->set('type', 'CADDY');
    $server->save();

    $config = Yaml::parse(generateDefaultProxyConfiguration($server->fresh()));

    // caddy-docker-proxy 2.13 ships Caddy 2.11, which knows log_append; the old 2.8 default shipped Caddy 2.7.6.
    expect($config['services']['caddy']['image'])->toBe('lucaslorentz/caddy-docker-proxy:2.13-alpine')
        ->and($server->fresh()->caddySupportsLogAppend())->toBeTrue();
});

function caddyTrafficServer(object $test, bool $analyticsEnabled = true): Server
{
    $server = Server::factory()->create(['team_id' => $test->team->id, 'private_key_id' => $test->privateKey->id]);
    $server->proxy->set('type', 'CADDY');
    $server->save();
    $server->settings->is_traffic_analytics_enabled = $analyticsEnabled;
    $server->settings->save();

    return $server->fresh();
}

it('mounts the Sentinel traffic log directory into Caddy in production', function () {
    $server = caddyTrafficServer($this);

    $config = Yaml::parse(generateDefaultProxyConfiguration($server));

    expect(StartSentinel::trafficLogDirectory($server))->toBe('/data/coolify/proxy/caddy')
        ->and($config['services']['caddy']['volumes'])->toContain('/data/coolify/proxy/caddy:/traffic');
});

it('mounts the Sentinel traffic log directory into Caddy in development', function () {
    config()->set('app.env', 'local');
    $server = caddyTrafficServer($this);

    $volumes = Yaml::parse(generateDefaultProxyConfiguration($server))['services']['caddy']['volumes'];
    $trafficVolumes = array_values(array_filter($volumes, fn (string $volume): bool => str_ends_with($volume, ':/traffic')));

    // Caddy writes /traffic/access.log, Sentinel reads <trafficLogDirectory>/access.log.
    expect($trafficVolumes)->toBe([StartSentinel::trafficLogDirectory($server).':/traffic'])
        ->and($trafficVolumes[0])->toBe('/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy:/traffic');
});

it('uses the configured dev data volume for Caddy, Traefik, and Sentinel', function () {
    config()->set('app.env', 'local');
    config()->set('constants.coolify.dev_data_volume', 'coolify-dev-feature_coolify_data');
    $caddy = caddyTrafficServer($this);

    $caddyVolumes = Yaml::parse(generateDefaultProxyConfiguration($caddy))['services']['caddy']['volumes'];

    $traefik = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $traefik->proxy->set('type', 'TRAEFIK');
    $traefik->save();
    $traefikVolumes = Yaml::parse(generateDefaultProxyConfiguration($traefik->fresh()))['services']['traefik']['volumes'];

    expect(StartSentinel::trafficLogDirectory($caddy))->toBe('/var/lib/docker/volumes/coolify-dev-feature_coolify_data/_data/proxy')
        ->and($caddyVolumes)->toContain('/var/lib/docker/volumes/coolify-dev-feature_coolify_data/_data/proxy:/traffic')
        ->and($traefikVolumes)->toContain('/var/lib/docker/volumes/coolify-dev-feature_coolify_data/_data/proxy/:/traefik');
});

it('falls back to the legacy dev data volume for an invalid volume name', function (?string $volume) {
    config()->set('app.env', 'local');
    config()->set('constants.coolify.dev_data_volume', $volume);

    expect(devCoolifyDataPath())->toBe('/var/lib/docker/volumes/coolify_dev_coolify_data/_data');
})->with([
    'empty' => [''],
    'null' => [null],
    'path traversal' => ['../../etc'],
    'shell characters' => ['vol;rm -rf /'],
]);

it('replaces a stale Caddy traffic mount and removes it when analytics is disabled', function () {
    config()->set('app.env', 'local');
    $server = caddyTrafficServer($this);
    $config = ['services' => ['caddy' => ['volumes' => [
        '/var/run/docker.sock:/var/run/docker.sock:ro',
        '/data/coolify/proxy/caddy:/traffic',
    ]]]];

    $enabled = applyTrafficAnalyticsToProxyConfigArray($server, $config);

    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();
    $disabled = applyTrafficAnalyticsToProxyConfigArray($server->fresh(), $enabled);

    expect($enabled['services']['caddy']['volumes'])->toBe([
        '/var/run/docker.sock:/var/run/docker.sock:ro',
        StartSentinel::trafficLogDirectory($server).':/traffic',
    ])->and($disabled['services']['caddy']['volumes'])->toBe([
        '/var/run/docker.sock:/var/run/docker.sock:ro',
    ]);
});
