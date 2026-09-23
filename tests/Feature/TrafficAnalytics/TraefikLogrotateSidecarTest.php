<?php

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

it('uses the latest stable traefik branch for new proxy configurations', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();

    $config = Yaml::parse(generateDefaultProxyConfiguration($server->fresh()));

    expect($config['services']['traefik']['image'])->toBe('traefik:v3.7');
});

it('does not expose the proxy container through its own docker provider', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();

    $config = Yaml::parse(generateDefaultProxyConfiguration($server->fresh()));
    $labels = $config['services']['traefik']['labels'];

    expect($labels)->toContain('traefik.enable=false')
        ->not->toContain('traefik.enable=true')
        ->and(collect($labels)->contains(fn (string $label): bool => str_starts_with($label, 'traefik.http.routers.traefik.')))->toBeFalse();
});

it('preserves intentional self-router labels when updating an existing proxy configuration', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();

    $configuration = applyTrafficAnalyticsToProxyConfiguration($server->fresh(), <<<'YAML'
services:
  traefik:
    command: []
    labels:
      - traefik.enable=true
      - traefik.http.routers.traefik.entrypoints=http
      - traefik.http.routers.traefik.service=api@internal
      - traefik.http.services.traefik.loadbalancer.server.port=8080
      - coolify.managed=true
    deploy:
      labels:
        - traefik.enable=true
        - traefik.http.routers.traefik.entrypoints=http
        - traefik.http.routers.traefik.service=api@internal
        - traefik.http.services.traefik.loadbalancer.server.port=8080
        - coolify.proxy=true
YAML);
    $traefik = Yaml::parse($configuration)['services']['traefik'];

    expect($traefik['labels'])->toContain(
        'traefik.enable=true',
        'traefik.http.routers.traefik.service=api@internal',
        'coolify.managed=true',
    )->and($traefik['deploy']['labels'])->toContain(
        'traefik.enable=true',
        'traefik.http.routers.traefik.service=api@internal',
        'coolify.proxy=true',
    );
});

it('does not add a traefik-logrotate sidecar when traffic analytics is disabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    $yaml = generateDefaultProxyConfiguration($server->fresh());

    expect($yaml)->not->toContain('traefik-logrotate');

    $config = Yaml::parse($yaml);
    expect($config['services'])->not->toHaveKey('traefik-logrotate');
});

it('adds a traefik-logrotate sidecar with copytruncate and the proxy mount when enabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    $server = $server->fresh();
    $yaml = generateDefaultProxyConfiguration($server);

    expect($yaml)->toContain('traefik-logrotate')
        ->toContain('copytruncate');

    $config = Yaml::parse($yaml);
    $sidecar = $config['services']['traefik-logrotate'];

    expect($sidecar['image'])->toBe('alpine:3.20');
    expect($sidecar['volumes'])->toContain($server->proxyPath().':/traefik');
    expect($sidecar['labels'])->toContain('coolify.managed=true');
    expect($sidecar['entrypoint'])->toContain('copytruncate');
    expect($sidecar['entrypoint'])->toContain('logrotate');
});
