<?php

use App\Models\Server;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ServerSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

function limaServerDefinitions(): array
{
    return [
        ['uuid' => 'lima-ubuntu-2404', 'port' => 2222, 'template' => 'ubuntu-2404.yaml', 'memory' => '2GiB', 'disk' => '20GiB'],
        ['uuid' => 'lima-ubuntu-2604', 'port' => 2223, 'template' => 'ubuntu-2604.yaml', 'memory' => '2GiB', 'disk' => '20GiB'],
    ];
}

it('seeds the development testing host and lima servers', function () {
    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ServerSeeder::class,
    ]);

    $testingHost = Server::query()->where('uuid', 'localhost')->first();

    expect($testingHost)
        ->not->toBeNull()
        ->and($testingHost->ip)->toBe('coolify-testing-host');

    foreach (limaServerDefinitions() as $definition) {
        $limaServer = Server::query()->where('uuid', $definition['uuid'])->first();

        expect($limaServer)
            ->not->toBeNull()
            ->and($limaServer->name)->toBe($definition['uuid'])
            ->and($limaServer->ip)->toBe('host.docker.internal')
            ->and($limaServer->port)->toBe($definition['port'])
            ->and($limaServer->user)->toBe('root')
            ->and($limaServer->team_id)->toBe(0)
            ->and($limaServer->private_key_id)->toBe(1)
            ->and($limaServer->settings)->not->toBeNull()
            ->and($limaServer->settings->sentinel_custom_url)->toBe('http://host.lima.internal:8000')
            ->and($limaServer->destinations())->toHaveCount(1);
    }
});

it('keeps the lima templates aligned with the seeded servers', function () {
    foreach (limaServerDefinitions() as $definition) {
        $template = Yaml::parseFile(base_path("docker/lima/{$definition['template']}"));
        $script = data_get($template, 'provision.0.script');

        expect(data_get($template, 'ssh.localPort'))
            ->toBe($definition['port'])
            ->and(data_get($template, 'memory'))->toBe($definition['memory'])
            ->and(data_get($template, 'disk'))->toBe($definition['disk'])
            ->and(data_get($template, 'containerd.system'))->toBeFalse()
            ->and(data_get($template, 'containerd.user'))->toBeFalse()
            ->and(data_get($template, 'networks'))->toBeNull()
            ->and($script)->toContain('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68');
    }
});
