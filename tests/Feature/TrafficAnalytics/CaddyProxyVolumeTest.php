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
