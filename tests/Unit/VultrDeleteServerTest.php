<?php

use App\Actions\Server\DeleteServer;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $this->team = Team::factory()->create();
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Private Key',
        'description' => 'Test private key',
        'private_key' => vultrDeleteTestPrivateKey(),
    ]);

    $this->vultrToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'vultr',
        'token' => 'test-vultr-token',
        'name' => 'Test Vultr Token',
    ]);
});

function vultrDeleteTestPrivateKey(): string
{
    return <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
}

it('deletes a Vultr instance when requested', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([], 204),
    ]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'cloud_provider_token_id' => $this->vultrToken->id,
        'vultr_instance_id' => 'instance-1',
    ]);

    $server->delete();

    DeleteServer::run(
        serverId: $server->id,
        cloudProviderTokenId: $this->vultrToken->id,
        teamId: $this->team->id,
        deleteFromVultr: true,
        vultrInstanceId: 'instance-1'
    );

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vultr.com/v2/instances/instance-1');

    expect(Server::withTrashed()->find($server->id))->toBeNull();
});

it('does not use another team Vultr token when deleting an instance', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/instance-1' => Http::response([], 204),
    ]);

    $otherTeam = Team::factory()->create();
    $otherToken = CloudProviderToken::create([
        'team_id' => $otherTeam->id,
        'provider' => 'vultr',
        'token' => 'other-team-vultr-token',
        'name' => 'Other Team Vultr Token',
    ]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'cloud_provider_token_id' => $this->vultrToken->id,
        'vultr_instance_id' => 'instance-1',
    ]);

    $server->delete();

    DeleteServer::run(
        serverId: $server->id,
        cloudProviderTokenId: $otherToken->id,
        teamId: $this->team->id,
        deleteFromVultr: true,
        vultrInstanceId: 'instance-1'
    );

    $request = Http::recorded()->first()[0];

    expect($request->header('Authorization'))->toBe(['Bearer test-vultr-token']);
});
