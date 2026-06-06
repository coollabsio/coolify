<?php

use App\Models\DockerNetwork;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

/**
 * proxy_access filtering policy used by StartProxy, RestartProxyJob and
 * ConnectProxyToNetworksJob. All three flows build their connect commands
 * through the single connectProxyToNetworks() helper, so exercising that
 * helper exercises the shared filtering contract for every flow.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => false,
        'force_disabled' => false,
    ]);
});

function createDestination(Server $server, string $network): StandaloneDocker
{
    return StandaloneDocker::withoutEvents(function () use ($server, $network) {
        $destination = (new StandaloneDocker)->forceFill([
            'uuid' => (string) new Cuid2,
            'name' => $server->name.'-'.$network,
            'network' => $network,
            'server_id' => $server->id,
        ]);
        $destination->save();

        return $destination;
    });
}

function createCatalogEntry(Server $server, string $network, ?bool $proxyAccess = null): DockerNetwork
{
    return DockerNetwork::query()->create([
        'server_id' => $server->id,
        'display_name' => $network,
        'docker_network_name' => $network,
        'is_active' => true,
        'is_system' => false,
        'managed_by_coolify' => false,
        'external' => true,
        'proxy_access' => $proxyAccess,
    ]);
}

function buildProxyCommands(Server $server): string
{
    return connectProxyToNetworks($server)->implode("\n");
}

it('keeps null policy legacy-enabled for the StartProxy flow', function () {
    createDestination($this->server, 'legacy-net');
    createCatalogEntry($this->server, 'legacy-net', proxyAccess: null);

    expect(buildProxyCommands($this->server))->toContain('legacy-net');
});

it('keeps explicit true policy enabled for the StartProxy flow', function () {
    createDestination($this->server, 'enabled-net');
    createCatalogEntry($this->server, 'enabled-net', proxyAccess: true);

    expect(buildProxyCommands($this->server))->toContain('enabled-net');
});

it('skips explicit false policy in the StartProxy flow', function () {
    createDestination($this->server, 'disabled-net');
    createCatalogEntry($this->server, 'disabled-net', proxyAccess: false);

    expect(buildProxyCommands($this->server))->not->toContain('disabled-net');
});

it('keeps null policy legacy-enabled for the RestartProxyJob flow', function () {
    createDestination($this->server, 'legacy-net');
    createCatalogEntry($this->server, 'legacy-net', proxyAccess: null);

    expect(buildProxyCommands($this->server))->toContain('legacy-net');
});

it('keeps explicit true policy enabled for the RestartProxyJob flow', function () {
    createDestination($this->server, 'enabled-net');
    createCatalogEntry($this->server, 'enabled-net', proxyAccess: true);

    expect(buildProxyCommands($this->server))->toContain('enabled-net');
});

it('skips explicit false policy in the RestartProxyJob flow', function () {
    createDestination($this->server, 'disabled-net');
    createCatalogEntry($this->server, 'disabled-net', proxyAccess: false);

    expect(buildProxyCommands($this->server))->not->toContain('disabled-net');
});

it('keeps null policy legacy-enabled for the ConnectProxyToNetworksJob flow', function () {
    createDestination($this->server, 'legacy-net');
    createCatalogEntry($this->server, 'legacy-net', proxyAccess: null);

    expect(buildProxyCommands($this->server))->toContain('legacy-net');
});

it('keeps explicit true policy enabled for the ConnectProxyToNetworksJob flow', function () {
    createDestination($this->server, 'enabled-net');
    createCatalogEntry($this->server, 'enabled-net', proxyAccess: true);

    expect(buildProxyCommands($this->server))->toContain('enabled-net');
});

it('skips explicit false policy in the ConnectProxyToNetworksJob flow', function () {
    createDestination($this->server, 'disabled-net');
    createCatalogEntry($this->server, 'disabled-net', proxyAccess: false);

    expect(buildProxyCommands($this->server))->not->toContain('disabled-net');
});

it('mixes null, true and false policies in any order in a single run', function () {
    createDestination($this->server, 'legacy-net');
    createCatalogEntry($this->server, 'legacy-net', proxyAccess: null);
    createDestination($this->server, 'enabled-net');
    createCatalogEntry($this->server, 'enabled-net', proxyAccess: true);
    createDestination($this->server, 'disabled-net');
    createCatalogEntry($this->server, 'disabled-net', proxyAccess: false);

    $commands = buildProxyCommands($this->server);

    expect($commands)
        ->toContain('legacy-net')
        ->toContain('enabled-net')
        ->not->toContain('disabled-net');
});

it('keeps unknown networks (no catalog row) legacy-enabled to preserve prior behavior', function () {
    createDestination($this->server, 'orphan-net');

    expect(buildProxyCommands($this->server))->toContain('orphan-net');
});
