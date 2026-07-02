<?php

use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

it('attaches gzip to the HTTPS dashboard router in Traefik dynamic config', function () {
    config(['constants.ssh.mux_enabled' => false]);

    Storage::fake('ssh-keys');
    Process::fake(['*' => Process::result(exitCode: 0)]);

    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => 'https://coolify.example.com',
    ]);

    $team = Team::factory()->create();
    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

    $server = Server::forceCreate([
        'id' => 0,
        'name' => 'localhost',
        'ip' => 'host.docker.internal',
        'user' => 'root',
        'port' => 22,
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
        ],
    ]);

    $server->setupDynamicProxyConfiguration();

    $generatedYaml = null;

    Process::assertRan(function ($process) use (&$generatedYaml) {
        if (! str_contains($process->command, 'coolify.yaml')) {
            return false;
        }

        preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*coolify\\.yaml/", $process->command, $matches);

        if (empty($matches[1])) {
            return false;
        }

        $generatedYaml = base64_decode($matches[1]);

        return $generatedYaml !== false;
    });

    $config = Yaml::parse($generatedYaml);
    $routers = $config['http']['routers'];

    expect($routers['coolify-http']['middlewares'])->toBe(['redirect-to-https'])
        ->and($routers['coolify-https']['middlewares'])->toBe(['gzip'])
        ->and(array_key_exists('middlewares', $routers['coolify-realtime-wss']))->toBeFalse()
        ->and(array_key_exists('middlewares', $routers['coolify-terminal-wss']))->toBeFalse();
});
