<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
    ]);
});

it('shows the lima start command on lima server general pages in development', function () {
    config()->set('app.env', 'local');

    foreach (limaStartCommandDefinitions() as $definition) {
        $server = createServerForLimaStartCommandTest($definition['uuid'], $definition['port']);

        $this->get(route('server.show', ['server_uuid' => $server->uuid]))
            ->assertSuccessful()
            ->assertSee('Start this Lima VM locally')
            ->assertSee($definition['command']);
    }
});

it('does not show the lima start command outside development', function () {
    config()->set('app.env', 'testing');

    $server = createServerForLimaStartCommandTest('lima-ubuntu-2404', 2222);

    $this->get(route('server.show', ['server_uuid' => $server->uuid]))
        ->assertSuccessful()
        ->assertDontSee('Start this Lima VM locally')
        ->assertDontSee('limactl start --yes --name=coolify-lima-ubuntu-2404 docker/lima/ubuntu-2404.yaml');
});

function createServerForLimaStartCommandTest(string $uuid, int $port): Server
{
    return Server::factory()->create([
        'uuid' => $uuid,
        'name' => $uuid,
        'ip' => 'host.docker.internal',
        'port' => $port,
        'team_id' => test()->team->id,
        'private_key_id' => test()->privateKey->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);
}

function limaStartCommandDefinitions(): array
{
    return [
        [
            'uuid' => 'lima-ubuntu-2404',
            'port' => 2222,
            'command' => 'limactl start --yes --name=coolify-lima-ubuntu-2404 docker/lima/ubuntu-2404.yaml',
        ],
        [
            'uuid' => 'lima-ubuntu-2604',
            'port' => 2223,
            'command' => 'limactl start --yes --name=coolify-lima-ubuntu-2604 docker/lima/ubuntu-2604.yaml',
        ],
    ];
}
