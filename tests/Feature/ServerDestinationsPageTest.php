<?php

use App\Livewire\Destination\DockerNetworks;
use App\Livewire\Destination\Index;
use App\Models\DockerNetwork;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Docker\DockerNetworkManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');
    $this->withoutVite();

    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('global destinations page renders docker network management for selected server with no destinations', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => true,
    ]);

    StandaloneDocker::withoutEvents(fn () => $server->standaloneDockers()->delete());

    $this->get(route('destination.index', ['server' => $server->uuid]))
        ->assertSuccessful()
        ->assertSeeLivewire(DockerNetworks::class)
        ->assertSee('Destinations')
        ->assertSee('Networks')
        ->assertSee('No Docker networks known yet.')
        ->assertDontSee('Server not found.');
});

test('global destinations page represents destinations in summary and selected server network rows', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    DockerNetwork::query()->create([
        'server_id' => $server->id,
        'docker_network_name' => $destination->network,
        'display_name' => $destination->name,
        'driver' => 'bridge',
        'scope' => 'local',
        'managed_by_coolify' => true,
        'external' => false,
        'is_system' => false,
        'is_active' => true,
        'source_type' => 'standalone_docker_destination',
        'network_role' => 'default_destination',
        'available_during_creation' => true,
    ]);

    $this->get(route('destination.index', ['server' => $server->uuid]))
        ->assertSuccessful()
        ->assertSee($destination->name)
        ->assertSee($destination->network)
        ->assertSee('View Destination')
        ->assertDontSee('View associated resources');
});

test('server destinations route redirects to global destinations with selected server', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    $this->get(route('server.destinations', ['server_uuid' => $server->uuid]))
        ->assertRedirect(route('destination.index', ['server' => $server->uuid]));
});

test('legacy docker networks route redirects to destinations', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    $this->get(route('server.docker-networks', ['server_uuid' => $server->uuid]))
        ->assertRedirect(route('destination.index', ['server' => $server->uuid]));
});

test('server sidebar has no separate docker networks link', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $this->get(route('server.show', ['server_uuid' => $server->uuid]))
        ->assertSuccessful()
        ->assertSee(route('destination.index', ['server' => $server->uuid]), false)
        ->assertDontSee(route('server.docker-networks', ['server_uuid' => $server->uuid]), false)
        ->assertDontSee(route('server.destinations', ['server_uuid' => $server->uuid]), false);
});

test('cataloged network does not automatically become a destination', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $destinationCount = $server->standaloneDockers()->count();

    DockerNetwork::query()->create([
        'server_id' => $server->id,
        'docker_network_name' => 'discovered-only',
        'display_name' => 'Discovered Only',
        'driver' => 'bridge',
        'scope' => 'local',
        'managed_by_coolify' => false,
        'external' => true,
        'is_system' => false,
        'is_active' => true,
        'source_type' => 'unknown',
        'network_role' => 'unknown',
    ]);

    $this->get(route('destination.index', ['server' => $server->uuid]))
        ->assertSuccessful()
        ->assertSee('Discovered Only');

    expect($server->standaloneDockers()->count())->toBe($destinationCount);
});

test('global destinations page shows all destinations and limits docker networks to selected server', function () {
    $serverWithDestination = Server::factory()->create(['team_id' => $this->team->id]);
    $serverWithDestination->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $serverWithDestination->standaloneDockers()->first()->update([
        'name' => 'Other Server Destination',
        'network' => 'other-server-destination-network',
    ]);

    $serverWithoutDestination = Server::factory()->create(['team_id' => $this->team->id]);
    $serverWithoutDestination->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    StandaloneDocker::withoutEvents(fn () => $serverWithoutDestination->standaloneDockers()->delete());

    DockerNetwork::query()->create([
        'server_id' => $serverWithoutDestination->id,
        'docker_network_name' => 'selected-server-network',
        'display_name' => 'Selected Server Network',
        'driver' => 'bridge',
        'scope' => 'local',
        'managed_by_coolify' => false,
        'external' => true,
        'is_system' => false,
        'is_active' => true,
        'source_type' => 'unknown',
        'network_role' => 'unknown',
    ]);

    $this->get(route('destination.index', ['server' => $serverWithoutDestination->uuid]))
        ->assertSuccessful()
        ->assertSee('Selected Server Network')
        ->assertSee('Other Server Destination')
        ->assertDontSee('other-server-destination-network');
});

test('destination summary lists authorized destinations from multiple servers with existing routes', function () {
    $firstServer = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'Alpha Server']);
    $secondServer = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'Beta Server']);
    $otherTeam = Team::factory()->create();
    $unauthorizedServer = Server::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Hidden Server']);

    $firstDestination = $firstServer->standaloneDockers()->firstOrFail();
    $firstDestination->update(['name' => 'Alpha Destination', 'network' => 'alpha-network']);
    $secondDestination = $secondServer->standaloneDockers()->firstOrFail();
    $secondDestination->update(['name' => 'Beta Destination', 'network' => 'beta-network']);
    $unauthorizedServer->standaloneDockers()->firstOrFail()->update(['name' => 'Hidden Destination']);

    Livewire::test(Index::class, ['server' => $firstServer->uuid])
        ->assertSee('Alpha Destination')
        ->assertSee('Beta Destination')
        ->assertSee('Alpha Server')
        ->assertSee('Beta Server')
        ->assertSee(route('destination.show', ['destination_uuid' => $firstDestination->uuid]), false)
        ->assertSee(route('destination.show', ['destination_uuid' => $secondDestination->uuid]), false)
        ->assertDontSee('Hidden Destination');
});

test('destination creation renders in modal with required controls and no abandoned options', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(Index::class, ['server' => $server->uuid])
        ->assertSet('createServerUuid', $server->uuid)
        ->assertSet('createDockerNetworkName', fn (string $name): bool => str_starts_with($name, 'coolify-net-'))
        ->assertSee('Add Destination')
        ->assertSee('Display name')
        ->assertSee('Docker network name')
        ->assertSee('Internal network')
        ->assertSee('Allow Coolify proxy access')
        ->assertSee('Automatic')
        ->assertDontSee('Attachable')
        ->assertDontSee('Make default Destination');
});

test('destination creation validates server selection and docker network name', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(Index::class, ['server' => $server->uuid])
        ->set('createDisplayName', 'Invalid Destination')
        ->set('createDockerNetworkName', 'invalid network name')
        ->set('createServerUuid', '')
        ->call('createDestination')
        ->assertHasErrors([
            'createDockerNetworkName' => 'regex',
            'createServerUuid' => 'required',
        ]);
});

test('internal destination creation disables proxy access', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(Index::class, ['server' => $server->uuid])
        ->set('createProxyAccess', true)
        ->set('createInternal', true)
        ->assertSet('createProxyAccess', false);
});

test('successful destination creation updates summary and selected inventory', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function create(Server $server, array $data): DockerNetwork
        {
            return DockerNetwork::query()->create([
                'server_id' => $server->id,
                'display_name' => $data['display_name'],
                'docker_network_name' => $data['docker_network_name'],
                'driver' => 'bridge',
                'scope' => 'local',
                'managed_by_coolify' => true,
                'external' => false,
                'is_system' => false,
                'is_active' => true,
                'source_type' => 'managed_custom',
                'network_role' => 'managed_custom',
            ]);
        }
    });

    Livewire::test(Index::class, ['server' => $server->uuid])
        ->set('createDisplayName', 'Analytics Destination')
        ->set('createDockerNetworkName', 'analytics-network')
        ->set('createServerUuid', $server->uuid)
        ->call('createDestination')
        ->assertHasNoErrors()
        ->assertSee('Analytics Destination')
        ->assertSee('analytics-network')
        ->assertSet('inventoryVersion', 1)
        ->assertDispatched('destination-created')
        ->assertDispatched('success');

    expect(StandaloneDocker::query()
        ->where('server_id', $server->id)
        ->where('network', 'analytics-network')
        ->where('name', 'Analytics Destination')
        ->count())->toBe(1);
});

test('docker networks section renders server selector for inventory scope', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(DockerNetworks::class, [
        'server_uuid' => $server->uuid,
        'server_options' => [
            ['uuid' => $server->uuid, 'name' => $server->name],
            ['uuid' => $otherServer->uuid, 'name' => $otherServer->name],
        ],
    ])
        ->assertSet('selectedInventoryServerUuid', $server->uuid)
        ->assertSee('Networks')
        ->assertSee($server->name)
        ->assertSee($otherServer->name);
});

test('global destinations page renders a server empty state when no servers exist', function () {
    $this->get(route('destination.index'))
        ->assertSuccessful()
        ->assertSee('No servers available.');
});

test('global destinations page rejects cross-team selected server from query string', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherNetwork = DockerNetwork::query()->create([
        'server_id' => $otherServer->id,
        'docker_network_name' => 'other-team-network',
        'display_name' => 'Other Team Network',
        'driver' => 'bridge',
        'scope' => 'local',
        'managed_by_coolify' => false,
        'external' => true,
        'is_system' => false,
        'is_active' => true,
        'source_type' => 'unknown',
        'network_role' => 'unknown',
    ]);

    expect($otherNetwork)->toBeInstanceOf(DockerNetwork::class);

    $this->get(route('destination.index', ['server' => $otherServer->uuid]))
        ->assertSuccessful()
        ->assertSee($server->name)
        ->assertDontSee('Other Team Network')
        ->assertDontSee($otherServer->name);
});
