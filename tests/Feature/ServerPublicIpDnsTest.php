<?php

use App\Actions\Shared\CheckDomainDns;
use App\Livewire\Server\Show;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Dns\DnsRecord;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\ServerDnsAddresses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_api_enabled' => true,
        'is_dns_validation_enabled' => true,
        'custom_dns_servers' => '192.0.2.1',
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '10.0.0.5']);
});

function fakeDnsAnswer(string $address): void
{
    app()->bind(DnsResolver::class, fn () => new class('192.0.2.1', $address) extends DnsResolver
    {
        public function __construct(string $server, private readonly string $address)
        {
            parent::__construct($server);
        }

        public function query(string $question, string $typeName = DnsResolver::TYPE_A): array|false
        {
            return [new DnsRecord($typeName, $this->address)];
        }

        public function hasError(): bool
        {
            return false;
        }
    });
}

it('validates DNS against the public IP of a server that Coolify reaches over a private IP', function () {
    $this->server->settings->update(['public_ipv4' => '95.217.1.10']);
    $server = $this->server->fresh();
    fakeDnsAnswer('95.217.1.10');

    $expectedIp = serverDnsTargetIp($server);
    $result = CheckDomainDns::run(['https://app.example.com' => 'https://app.example.com'], $server, $expectedIp);

    expect($expectedIp)->toBe('95.217.1.10')
        ->and($result['https://app.example.com']['status'])->toBe('ok')
        ->and(validateDNSEntry('https://app.example.com', $server))->toBeTrue();
});

it('still validates DNS against the server IP when no public IP is set', function () {
    fakeDnsAnswer('95.217.1.10');

    $expectedIp = serverDnsTargetIp($this->server);
    $result = CheckDomainDns::run(['https://app.example.com' => 'https://app.example.com'], $this->server, $expectedIp);

    expect($expectedIp)->toBe('10.0.0.5')
        ->and($result['https://app.example.com']['status'])->toBe('failed');
});

it('offers the public IPs of the server as DNS record content instead of its private IP', function () {
    $this->server->settings->update(['public_ipv4' => '95.217.1.10', 'public_ipv6' => '2A01:04F8::0010']);

    $addresses = ServerDnsAddresses::forServer($this->server->fresh());

    expect($addresses->publicAddresses())->toBe(['95.217.1.10', '2a01:4f8::10']);
});

it('keeps the IPv4 of the server when only a public IPv6 is set', function () {
    $this->server->update(['ip' => '95.217.1.20']);
    $this->server->settings->update(['public_ipv6' => '2a01:4f8::10']);

    $addresses = ServerDnsAddresses::forServer($this->server->fresh());

    expect($addresses->addresses())->toBe(['95.217.1.20', '2a01:4f8::10'])
        ->and($addresses->publicAddresses())->toBe(['95.217.1.20', '2a01:4f8::10']);
});

it('keeps the IPv6 of the server when only a public IPv4 is set', function () {
    $this->server->update(['ip' => '2a01:4f8::20']);
    $this->server->settings->update(['public_ipv4' => '95.217.1.10']);

    expect(ServerDnsAddresses::forServer($this->server->fresh())->addresses())->toBe(['95.217.1.10', '2a01:4f8::20']);
});

it('saves the public IPs from the server page', function () {
    Livewire::actingAs($this->user)
        ->test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('publicIpv4', '95.217.1.10')
        ->set('publicIpv6', '2a01:4f8::10')
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->server->settings->fresh())
        ->public_ipv4->toBe('95.217.1.10')
        ->public_ipv6->toBe('2a01:4f8::10');
});

it('rejects a public IPv4 that is not an IPv4 address on the server page', function () {
    Livewire::actingAs($this->user)
        ->test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('publicIpv4', '2a01:4f8::10')
        ->call('submit')
        ->assertDispatched('error');

    expect($this->server->settings->fresh()->public_ipv4)->toBeNull();
});

it('sets and removes the public IPs through the API', function () {
    $token = $this->user->createToken('write-token', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $this->withHeaders($headers)
        ->patchJson('/api/v1/servers/'.$this->server->uuid, ['public_ipv4' => '95.217.1.10', 'public_ipv6' => '2A01:4F8::10'])
        ->assertStatus(201);
    expect($this->server->settings->fresh())
        ->public_ipv4->toBe('95.217.1.10')
        ->public_ipv6->toBe('2a01:4f8::10');

    $this->withHeaders($headers)
        ->patchJson('/api/v1/servers/'.$this->server->uuid, ['public_ipv4' => null])
        ->assertStatus(201);
    expect($this->server->settings->fresh()->public_ipv4)->toBeNull();

    $this->withHeaders($headers)
        ->patchJson('/api/v1/servers/'.$this->server->uuid, ['public_ipv6' => '95.217.1.10'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['public_ipv6']]);
});
