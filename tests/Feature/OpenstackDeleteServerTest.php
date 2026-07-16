<?php

use App\Actions\Server\DeleteServer;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

if (! function_exists('osDeleteRsaKey')) {
    function osDeleteRsaKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);

        return $privateKey;
    }
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $catalog = [
        ['type' => 'compute', 'endpoints' => [['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://compute.example/v2.1']]],
        ['type' => 'network', 'endpoints' => [['interface' => 'public', 'region_id' => 'RegionOne', 'url' => 'https://network.example']]],
    ];
    $this->authFake = [
        'identity.example/v3/auth/tokens' => Http::response(['token' => ['catalog' => $catalog]], 201, ['X-Subject-Token' => 'tok']),
    ];

    $this->osToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'openstack',
        'name' => 'Test OpenStack',
        'token' => json_encode([
            'auth_url' => 'https://identity.example/v3',
            'application_credential_id' => 'app-id',
            'application_credential_secret' => 'app-secret',
            'region' => 'RegionOne',
        ]),
    ]);

    $this->privateKey = PrivateKey::create([
        'name' => 'coolify-del-key',
        'private_key' => osDeleteRsaKey(),
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::create([
        'name' => 'os-delete-server',
        'ip' => '203.0.113.5',
        'user' => 'root',
        'port' => 22,
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'cloud_provider_token_id' => $this->osToken->id,
        'openstack_server_id' => 'srv-1',
        'openstack_floating_ip_id' => 'fip-1',
    ]);
});

it('releases the floating IP and deletes the instance from OpenStack', function () {
    Http::fake(array_merge($this->authFake, [
        'network.example/v2.0/floatingips/fip-1' => Http::response(null, 204),
        'compute.example/v2.1/servers/srv-1' => Http::response(null, 204),
    ]));

    DeleteServer::run(
        serverId: $this->server->id,
        deleteFromOpenstack: true,
        openstackServerId: 'srv-1',
        openstackFloatingIpId: 'fip-1',
        teamId: $this->team->id,
    );

    Http::assertSent(fn ($request) => str_contains($request->url(), '/floatingips/fip-1') && $request->method() === 'DELETE');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/servers/srv-1') && $request->method() === 'DELETE');

    expect(Server::find($this->server->id))->toBeNull();
});

it('does not call OpenStack when deleteFromOpenstack is false', function () {
    Http::fake($this->authFake);

    DeleteServer::run(
        serverId: $this->server->id,
        deleteFromOpenstack: false,
    );

    Http::assertNothingSent();
    expect(Server::find($this->server->id))->toBeNull();
});
