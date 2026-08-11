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

    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $this->team->id,
    ]);
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
