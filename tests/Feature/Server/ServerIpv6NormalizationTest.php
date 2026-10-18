<?php

use App\Jobs\ServerCloudProviderStatusCheckJob;
use App\Livewire\Server\New\ByHetzner;
use App\Livewire\Server\New\ByIp;
use App\Livewire\Server\Show;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Process::fake();
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function createServerForIpv6NormalizationTest(Team $team, PrivateKey $privateKey, string $ip, array $attributes = []): Server
{
    return Server::factory()->create(array_merge([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => $ip,
    ], $attributes));
}

it('stores IPv6 addresses in the short lower-case form', function (string $input, string $expected) {
    $server = createServerForIpv6NormalizationTest($this->team, $this->privateKey, $input);

    expect($server->fresh()->ip)->toBe($expected);
})->with([
    'upper case with zeros' => ['2A01:04F8:C016:BD23:0000:0000:0000:0001', '2a01:4f8:c016:bd23::1'],
    'in brackets' => ['[2a01:4f8:c016:bd23::1]', '2a01:4f8:c016:bd23::1'],
    'IPv4' => ['203.0.113.5', '203.0.113.5'],
    'hostname' => ['Server.Example.com', 'Server.Example.com'],
]);

it('stores a normalized address when backfilling a placeholder IP', function () {
    $server = createServerForIpv6NormalizationTest($this->team, $this->privateKey, Server::PLACEHOLDER_IP);

    expect($server->backfillPlaceholderIp('2A01:04F8::0001'))->toBeTrue()
        ->and($server->ip)->toBe('2a01:4f8::1')
        ->and($server->fresh()->ip)->toBe('2a01:4f8::1');
});

it('builds Traefik dashboard host rules without IPv6 brackets', function () {
    $server = new Server;

    expect($server->dashboardTraefikHost('[2a01:4f8:c016:bd23::1]'))->toBe('2a01:4f8:c016:bd23::1')
        ->and($server->dashboardTraefikHost('dashboard.example.com'))->toBe('dashboard.example.com');
});

it('treats IPv6 notations as the same IP when adding a server', function () {
    createServerForIpv6NormalizationTest($this->team, $this->privateKey, '2001:db8::1');

    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->privateKey]),
        'limit_reached' => false,
    ])
        ->set('ip', '2001:0DB8::1')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertDispatched('error', 'A server with this IP/Domain already exists.');

    expect(Server::query()->where('ip', '2001:db8::1')->count())->toBe(1);
});

it('adds a server with a bracketed IPv6 address in the stored form', function () {
    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->privateKey]),
        'limit_reached' => false,
    ])
        ->set('name', 'ipv6-server')
        ->set('ip', '[2001:0DB8::5]')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Server::query()->where('name', 'ipv6-server')->value('ip'))->toBe('2001:db8::5');
});

it('treats IPv6 notations as the same IP when editing a server', function () {
    createServerForIpv6NormalizationTest($this->team, $this->privateKey, '2001:db8::1');
    $server = createServerForIpv6NormalizationTest($this->team, $this->privateKey, '203.0.113.5');

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set('ip', '2001:0DB8::1')
        ->call('submit')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($server->fresh()->ip)->toBe('203.0.113.5');
});

it('saves other server fields when the unchanged IP is also used by another server', function () {
    InstanceSettings::get()->update(['fqdn' => 'https://coolify.example.com']);
    createServerForIpv6NormalizationTest($this->team, $this->privateKey, '2001:db8::1');
    $server = createServerForIpv6NormalizationTest($this->team, $this->privateKey, '203.0.113.5');
    DB::table('servers')->where('id', $server->id)->update(['ip' => '2001:db8::1']);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set('name', 'renamed-server')
        ->call('submit')
        ->assertNotDispatched('error');

    expect($server->fresh()->name)->toBe('renamed-server');
});

it('saves the normalized IPv6 address when editing a server', function () {
    $server = createServerForIpv6NormalizationTest($this->team, $this->privateKey, '203.0.113.5');

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set('ip', '[2001:0DB8::7]')
        ->call('submit')
        ->assertSet('ip', '2001:db8::7');

    expect($server->fresh()->ip)->toBe('2001:db8::7');
});

it('stores the Hetzner IPv6 server address when creating an IPv6-only server', function () {
    $token = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Test Hetzner Token',
    ]);

    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
            'ssh_key' => ['id' => 42, 'fingerprint' => 'ff:ff'],
        ], 201),
        'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response(['ssh_keys' => []], 200),
        'https://api.hetzner.cloud/v1/servers' => Http::response([
            'server' => [
                'id' => 777,
                'status' => 'running',
                'public_net' => [
                    'ipv4' => null,
                    'ipv6' => ['ip' => '2a01:4f8:c016:bd23::/64'],
                ],
            ],
        ], 201),
    ]);

    Livewire::test(ByHetzner::class, ['selectedTokenUuid' => $token->uuid])
        ->set('server_name', 'hetzner-ipv6')
        ->set('selected_location', 'fsn1')
        ->set('selected_server_type', 'cx22')
        ->set('selected_image', 114690387)
        ->set('private_key_id', $this->privateKey->id)
        ->set('enable_ipv4', false)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Server::query()->where('hetzner_server_id', '777')->value('ip'))->toBe('2a01:4f8:c016:bd23::1');
});

it('backfills the Hetzner IPv6 server address during the status sync', function () {
    $token = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Hetzner',
    ]);
    $server = createServerForIpv6NormalizationTest($this->team, $this->privateKey, Server::PLACEHOLDER_IP, [
        'cloud_provider_token_id' => $token->id,
        'hetzner_server_id' => 123,
        'hetzner_server_status' => 'starting',
    ]);

    Http::fake([
        'https://api.hetzner.cloud/v1/servers/123' => Http::response([
            'server' => [
                'id' => 123,
                'status' => 'running',
                'public_net' => [
                    'ipv4' => null,
                    'ipv6' => ['ip' => '2a01:4f8:c016:bd23::/64'],
                ],
            ],
        ]),
    ]);

    (new ServerCloudProviderStatusCheckJob($server))->handle();

    expect($server->fresh()->ip)->toBe('2a01:4f8:c016:bd23::1');
});

it('backfills the Hetzner IPv6 server address from the server page', function () {
    $token = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Hetzner',
    ]);
    $server = createServerForIpv6NormalizationTest($this->team, $this->privateKey, Server::PLACEHOLDER_IP, [
        'cloud_provider_token_id' => $token->id,
        'hetzner_server_id' => 123,
        'hetzner_server_status' => 'starting',
    ]);

    Http::fake([
        'https://api.hetzner.cloud/v1/servers/123' => Http::response([
            'server' => [
                'id' => 123,
                'status' => 'running',
                'public_net' => [
                    'ipv4' => null,
                    'ipv6' => ['ip' => '2a01:4f8:c016:bd23::/64'],
                ],
            ],
        ]),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->call('checkHetznerServerStatus', true)
        ->assertSet('ip', '2a01:4f8:c016:bd23::1');

    expect($server->fresh()->ip)->toBe('2a01:4f8:c016:bd23::1');
});
