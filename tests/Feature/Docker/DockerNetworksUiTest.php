<?php

use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkSourceType;
use App\Exceptions\DockerNetworkDeletionException;
use App\Jobs\ConnectProxyToNetworksJob;
use App\Livewire\Destination\DockerNetworks as Index;
use App\Models\Application;
use App\Models\DockerNetwork;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Docker\DockerNetworkCatalogRefresher;
use App\Services\Docker\DockerNetworkManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createDockerNetworksUiServer(bool $functional = true): Server
{
    $server = Server::factory()->create(['team_id' => test()->team->id]);
    $server->settings()->update([
        'is_reachable' => $functional,
        'is_usable' => $functional,
        'force_disabled' => false,
    ]);

    return $server->refresh();
}

function createCatalogNetwork(Server $server, array $attributes = []): DockerNetwork
{
    return DockerNetwork::create(array_merge([
        'server_id' => $server->id,
        'display_name' => 'Backend Network',
        'docker_network_name' => 'backend-net',
        'driver' => 'bridge',
        'scope' => 'local',
        'subnet' => '172.30.10.0/24',
        'gateway' => '172.30.10.1',
        'managed_by_coolify' => true,
        'external' => false,
        'is_system' => false,
        'is_active' => true,
        'source_type' => DockerNetworkSourceType::ManagedCustom->value,
        'network_role' => DockerNetworkRole::ManagedCustom->value,
        'last_inspected_at' => now(),
        'last_inspect_data' => [
            'docker_id' => 'network-id',
            'ipam_configs' => [
                ['Subnet' => '172.30.10.0/24', 'Gateway' => '172.30.10.1'],
            ],
            'containers' => [],
            'raw' => ['Name' => 'backend-net'],
        ],
    ], $attributes));
}

function createStandaloneDestinationForNetwork(Server $server, string $network = 'backend-net', array $attributes = []): StandaloneDocker
{
    return StandaloneDocker::withoutEvents(function () use ($server, $network, $attributes) {
        $destination = new StandaloneDocker;
        $destination->forceFill(array_merge([
            'uuid' => (string) new Cuid2,
            'server_id' => $server->id,
            'name' => 'Backend Destination',
            'network' => $network,
        ], $attributes))->save();

        return $destination;
    });
}

it('renders docker network management inside destinations and lists only current server networks', function () {
    $server = createDockerNetworksUiServer();
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    createCatalogNetwork($server);
    createCatalogNetwork($otherServer, [
        'display_name' => 'Other Network',
        'docker_network_name' => 'other-net',
    ]);

    $this->get(route('destination.index', ['server' => $server->uuid]))
        ->assertSuccessful()
        ->assertSeeLivewire(Index::class)
        ->assertSee('Networks')
        ->assertSee('Backend Network')
        ->assertSee('Created by Coolify')
        ->assertDontSee('Docker Network Name')
        ->assertDontSee('Other Network');
});

it('cannot manipulate a network from another server', function () {
    $server = createDockerNetworksUiServer();
    $otherServer = createDockerNetworksUiServer();
    $otherNetwork = createCatalogNetwork($otherServer);

    expect(fn () => Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('startEditing', $otherNetwork->id))
        ->toThrow(ModelNotFoundException::class);
});

it('clears proxy access when internal network is selected during creation', function () {
    $server = createDockerNetworksUiServer(false);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('createProxyAccess', true)
        ->set('createInternal', true)
        ->assertSet('createProxyAccess', false);
});

it('renders docker network status labels', function () {
    $server = createDockerNetworksUiServer();

    createCatalogNetwork($server, [
        'display_name' => 'Managed Network',
        'docker_network_name' => 'managed-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_system' => true,
        'is_active' => true,
        'network_role' => DockerNetworkRole::System->value,
    ]);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertSee('Managed Network')
        ->assertSee('Created by Coolify')
        ->assertSee('System')
        ->assertSee('Active')
        ->assertDontSee('Available during creation');
});

it('shows empty state when no networks are cataloged', function () {
    $server = createDockerNetworksUiServer();

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertSee('No Docker networks known yet.')
        ->assertSee('Refresh');
});

it('filters and searches cataloged networks', function () {
    $server = createDockerNetworksUiServer();
    createCatalogNetwork($server);
    createCatalogNetwork($server, [
        'display_name' => 'External Network',
        'docker_network_name' => 'external-net',
        'managed_by_coolify' => false,
        'external' => true,
        'source_type' => DockerNetworkSourceType::Unknown->value,
        'network_role' => DockerNetworkRole::Unknown->value,
    ]);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('filter', 'external')
        ->assertSee('External Network')
        ->assertDontSee('Backend Network')
        ->set('filter', 'all')
        ->set('search', 'backend-net')
        ->assertSee('Backend Network')
        ->assertDontSee('External Network');
});

it('does not block initial docker networks render while background refresh is pending', function () {
    $server = createDockerNetworksUiServer();
    $calls = 0;

    app()->instance(DockerNetworkCatalogRefresher::class, new class($calls) extends DockerNetworkCatalogRefresher
    {
        private int $callsRef;

        public function __construct(int &$calls)
        {
            $this->callsRef = &$calls;
        }

        public function refresh(Server $server, bool $force = false): Collection
        {
            $this->callsRef++;

            return collect([
                'found' => 0,
                'created' => 0,
                'updated' => 0,
                'removed' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertSuccessful()
        ->assertSee('Refreshing networks...');

    expect($calls)->toBe(0);
});

it('refreshes docker networks in background when requested', function () {
    $server = createDockerNetworksUiServer();
    $calls = 0;

    app()->instance(DockerNetworkCatalogRefresher::class, new class($calls) extends DockerNetworkCatalogRefresher
    {
        private int $callsRef;

        public function __construct(int &$calls)
        {
            $this->callsRef = &$calls;
        }

        public function refresh(Server $server, bool $force = false): Collection
        {
            $this->callsRef++;

            return collect([
                'found' => 0,
                'created' => 0,
                'updated' => 0,
                'removed' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('refreshNetworksInBackground')
        ->assertSet('refreshWarning', null);

    expect($calls)->toBe(1);
});

it('manual refresh shows summary and success message', function () {
    $server = createDockerNetworksUiServer();
    app()->instance(DockerNetworkCatalogRefresher::class, new class extends DockerNetworkCatalogRefresher
    {
        public function __construct() {}

        public function refresh(Server $server, bool $force = false): Collection
        {
            DockerNetwork::query()->firstOrCreate(
                [
                    'server_id' => $server->id,
                    'docker_network_name' => 'scanned-net',
                ],
                [
                    'display_name' => 'Scanned Network',
                    'driver' => 'bridge',
                    'scope' => 'local',
                    'subnet' => '172.30.10.0/24',
                    'gateway' => '172.30.10.1',
                    'managed_by_coolify' => true,
                    'external' => false,
                    'is_system' => false,
                    'is_active' => true,
                    'source_type' => DockerNetworkSourceType::ManagedCustom->value,
                    'network_role' => DockerNetworkRole::ManagedCustom->value,
                    'last_inspected_at' => now(),
                    'last_inspect_data' => [
                        'docker_id' => 'network-id',
                        'containers' => [],
                        'raw' => ['Name' => 'scanned-net'],
                    ],
                ],
            );

            return collect([
                'found' => 1,
                'created' => 1,
                'updated' => 0,
                'removed' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('scan')
        ->assertSee('Scanned Network')
        ->assertDispatched('success');
});

it('shows refresh warning without breaking the page when auto refresh fails', function () {
    $server = createDockerNetworksUiServer();
    createCatalogNetwork($server);
    app()->instance(DockerNetworkCatalogRefresher::class, new class extends DockerNetworkCatalogRefresher
    {
        public function __construct() {}

        public function refresh(Server $server, bool $force = false): Collection
        {
            return collect([
                'found' => 0,
                'created' => 0,
                'updated' => 0,
                'removed' => 0,
                'errors' => ['Unable to list Docker networks.'],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('refreshNetworksInBackground')
        ->assertSee('Could not refresh Docker networks. Showing last known state.')
        ->assertSee('Backend Network');
});

it('creates network through manager and refreshes list', function () {
    $server = createDockerNetworksUiServer();
    $refreshCalls = 0;
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function create(Server $server, array $data): DockerNetwork
        {
            return createCatalogNetwork($server, [
                'display_name' => $data['display_name'],
                'docker_network_name' => 'coolify-net-ui',
            ]);
        }
    });
    app()->instance(DockerNetworkCatalogRefresher::class, new class($refreshCalls) extends DockerNetworkCatalogRefresher
    {
        private int $callsRef;

        public function __construct(int &$refreshCalls)
        {
            $this->callsRef = &$refreshCalls;
        }

        public function refresh(Server $server, bool $force = false): Collection
        {
            $this->callsRef++;

            return collect([
                'found' => 1,
                'created' => 1,
                'updated' => 0,
                'removed' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('showCreateForm', true)
        ->set('createDisplayName', 'Created Network')
        ->set('createDockerNetworkName', 'coolify-net-ui')
        ->set('createDriver', 'bridge')
        ->call('createNetwork')
        ->assertSee('Created Network')
        ->assertDispatched('success');

    expect($refreshCalls)->toBe(1)
        ->and(StandaloneDocker::where('server_id', $server->id)->where('network', 'coolify-net-ui')->exists())->toBeTrue();
});

it('validates create display name', function () {
    $server = createDockerNetworksUiServer();

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('createDisplayName', '')
        ->call('createNetwork')
        ->assertHasErrors(['createDisplayName' => 'required']);
});

it('does not render redundant destination scan or manual import controls', function () {
    $server = createDockerNetworksUiServer();
    createCatalogNetwork($server);

    $this->get(route('destination.index', ['server' => $server->uuid]))
        ->assertSuccessful()
        ->assertSee('Refresh')
        ->assertSee('Add Destination')
        ->assertDontSee('Scan for Destinations')
        ->assertDontSee('Found Destinations')
        ->assertDontSee('Import existing network')
        ->assertDontSee('Import Existing Network');
});

it('uses eligible catalog network as destination without changing catalog ownership or proxy state', function () {
    Queue::fake();

    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server, [
        'managed_by_coolify' => false,
        'external' => true,
        'source_type' => DockerNetworkSourceType::Unknown->value,
        'network_role' => DockerNetworkRole::Unknown->value,
    ]);
    $destinationCount = StandaloneDocker::where('server_id', $server->id)->count();

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('useAsDestination', $network->id)
        ->assertSee('Destination')
        ->assertDispatched('success');

    $destination = StandaloneDocker::where('server_id', $server->id)
        ->where('network', 'backend-net')
        ->first();

    expect($destination)->not->toBeNull()
        ->and($destination->name)->toBe('Backend Network')
        ->and(StandaloneDocker::where('server_id', $server->id)->count())->toBe($destinationCount + 1)
        ->and($network->refresh()->available_during_creation)->toBeTrue()
        ->and($network->refresh()->managed_by_coolify)->toBeFalse();

    Queue::assertNotPushed(ConnectProxyToNetworksJob::class);
});

it('uses immutable docker name when network alias is blank', function () {
    Queue::fake();

    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server, ['display_name' => '']);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('useAsDestination', $network->id)
        ->assertDispatched('success');

    expect(StandaloneDocker::query()
        ->where('server_id', $server->id)
        ->where('network', 'backend-net')
        ->value('name'))->toBe('backend-net');
});

it('blocks duplicate destination promotion with a friendly message', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);

    createStandaloneDestinationForNetwork($server, attributes: ['name' => 'Existing Destination']);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('useAsDestination', $network->id)
        ->assertDispatched('error');

    expect(StandaloneDocker::where('server_id', $server->id)->where('network', 'backend-net')->count())->toBe(1);
});

it('does not allow system networks to be used as destinations', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server, [
        'display_name' => 'Bridge',
        'docker_network_name' => 'bridge',
        'is_system' => true,
        'network_role' => DockerNetworkRole::System->value,
    ]);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertDontSee('Use as Destination')
        ->assertDontSee('Delete network')
        ->call('useAsDestination', $network->id)
        ->assertDispatched('error');
});

it('does not show delete actions for reserved Coolify infrastructure networks', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server, [
        'display_name' => 'Coolify',
        'docker_network_name' => 'coolify',
        'is_system' => true,
        'network_role' => DockerNetworkRole::DefaultDestination->value,
    ]);
    $server->standaloneDockers()->where('network', 'coolify')->firstOrFail()->update(['name' => 'Coolify']);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertSee('Coolify')
        ->assertSee('View Destination')
        ->assertDontSee('Edit alias')
        ->assertDontSee('Remove Destination')
        ->assertDontSee('Delete network')
        ->assertDontSee('Confirm Docker Network Deletion?')
        ->call('startEditing', $network->id)
        ->assertDispatched('error')
        ->call('removeFromDestinations', $network->id)
        ->assertDispatched('error');
});

it('shows configured destination actions for matching network', function () {
    $server = createDockerNetworksUiServer();
    createCatalogNetwork($server);
    $destination = createStandaloneDestinationForNetwork($server);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertSee('Destination')
        ->assertSee('View Destination')
        ->assertSee(route('destination.show', ['destination_uuid' => $destination->uuid]), false)
        ->assertSee('Remove Destination')
        ->assertSee('Remove Destination?')
        ->assertSee('Delete Docker network permanently.')
        ->assertSee('Keep the Docker network on the server.')
        ->assertSee('This removes the network from Destinations. The Docker network will not be deleted.')
        ->assertSee('The real Docker network will be permanently deleted.')
        ->assertSee('Keep existing runtime containers and network data.')
        ->assertSee('Keep the network available in network management.')
        ->assertSee('Allow this network to be added as a Destination again later.')
        ->assertSee('Remove local network inventory and metadata after Docker confirms deletion.')
        ->assertSee('Docker network name')
        ->assertDontSee('confirm(')
        ->assertDontSee('Show during creation')
        ->assertDontSee('Hide from creation');
});

it('removes destination without deleting docker network', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    createStandaloneDestinationForNetwork($server);
    $network->update(['available_during_creation' => true]);

    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function delete(DockerNetwork $network): void
        {
            throw new RuntimeException('docker network rm should not run');
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('removeFromDestinations', $network->id)
        ->assertSee('Use as Destination')
        ->assertDispatched('success');

    expect(DockerNetwork::whereKey($network->id)->exists())->toBeTrue()
        ->and($network->refresh()->available_during_creation)->toBeFalse()
        ->and(StandaloneDocker::where('server_id', $server->id)->where('network', 'backend-net')->exists())->toBeFalse();
});

it('optionally deletes docker network when removing destination', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    createStandaloneDestinationForNetwork($server);

    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function deleteWithDestination(DockerNetwork $network): void
        {
            $network->delete();
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('removeFromDestinations', $network->id, '', ['deleteNetwork'])
        ->assertDispatched('success');

    expect(DockerNetwork::whereKey($network->id)->exists())->toBeFalse()
        ->and(StandaloneDocker::where('server_id', $server->id)->where('network', 'backend-net')->exists())->toBeFalse();
});

it('does not remove destination with attached resources', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    $destination = createStandaloneDestinationForNetwork($server);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('removeFromDestinations', $network->id)
        ->assertDispatched('error');

    expect($destination->refresh()->exists)->toBeTrue();
});

it('deletes destination association after destination-backed network deletion succeeds', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    createStandaloneDestinationForNetwork($server);

    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function deleteWithDestination(DockerNetwork $network): void
        {
            $network->delete();
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('deleteNetwork', $network->id, 'password')
        ->assertDispatched('success');

    expect(DockerNetwork::whereKey($network->id)->exists())->toBeFalse()
        ->and(StandaloneDocker::where('server_id', $server->id)->where('network', 'backend-net')->exists())->toBeFalse();
});

it('renames display name without changing docker network name', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('startEditing', $network->id)
        ->set('editDisplayName', 'Renamed Network')
        ->call('renameNetwork')
        ->assertSee('Renamed Network');

    expect($network->refresh()->display_name)->toBe('Renamed Network')
        ->and($network->docker_network_name)->toBe('backend-net');
});

it('inspect modal shows metadata ipam and containers', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server, [
        'ip_range' => null,
        'last_inspect_data' => [
            'docker_id' => 'network-id',
            'ipam_configs' => [
                [
                    'Subnet' => '172.30.10.0/24',
                    'Gateway' => '172.30.10.1',
                ],
                [
                    'Subnet' => 'fd00:cafe::/64',
                    'Gateway' => 'fd00:cafe::1',
                    'IPRange' => 'fd00:cafe::/80',
                ],
            ],
            'containers' => [
                'container-id' => [
                    'Name' => 'api',
                    'IPv4Address' => '172.30.10.2/24',
                    'IPv6Address' => '',
                    'MacAddress' => '02:42:ac:1e:0a:02',
                    'Aliases' => ['api'],
                ],
            ],
            'raw' => ['Name' => 'backend-net'],
        ],
    ]);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('selectNetwork', $network->id)
        ->assertSet('showInspectModal', true)
        ->assertSee('IPAM')
        ->assertSee('Allocation Range')
        ->assertSee('Automatic (entire subnet)')
        ->assertSee('fd00:cafe::/64')
        ->assertSee('fd00:cafe::/80')
        ->assertSee('network-id')
        ->assertSee('api')
        ->assertSee('172.30.10.2/24')
        ->assertSee('backend-net');
});

it('clears inspect modal state when search changes', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('selectNetwork', $network->id)
        ->assertSet('selectedNetworkId', $network->id)
        ->assertSet('showInspectModal', true)
        ->set('search', 'missing')
        ->assertSet('selectedNetworkId', null)
        ->assertSet('showInspectModal', false);
});

it('refresh inspect calls manager', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function inspect(DockerNetwork $network): array
        {
            $network->update(['last_inspect_data' => ['docker_id' => 'refreshed-id', 'containers' => [], 'raw' => []]]);

            return ['docker_id' => 'refreshed-id'];
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('selectNetwork', $network->id)
        ->call('refreshInspect')
        ->assertSee('refreshed-id')
        ->assertDispatched('success');
});

it('delete uses modal component and removes network from live inventory', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    $refreshCalls = 0;
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function delete(DockerNetwork $network): void
        {
            $network->delete();
        }
    });
    app()->instance(DockerNetworkCatalogRefresher::class, new class($refreshCalls) extends DockerNetworkCatalogRefresher
    {
        private int $callsRef;

        public function __construct(int &$refreshCalls)
        {
            $this->callsRef = &$refreshCalls;
        }

        public function refresh(Server $server, bool $force = false): Collection
        {
            $this->callsRef++;

            return collect([
                'found' => 1,
                'created' => 0,
                'updated' => 1,
                'removed' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertSee('Confirm Docker Network Deletion?')
        ->assertSee('Permanently Delete')
        ->assertDontSee('confirm(')
        ->call('deleteNetwork', $network->id, 'password')
        ->assertDontSee('Managed')
        ->assertDispatched('success');

    expect($refreshCalls)->toBe(1)
        ->and(DockerNetwork::whereKey($network->id)->exists())->toBeFalse();
});

it('allows custom unused external networks to be deleted from the UI', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server, [
        'managed_by_coolify' => false,
        'external' => true,
    ]);
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function canDelete(DockerNetwork $network): array
        {
            return [
                'allowed' => true,
                'reason_code' => null,
                'message' => '',
                'container_count' => 0,
                'containers' => [],
                'resources' => [],
                'blocking_dependencies' => [],
                'proxy_disconnect_required' => false,
            ];
        }

        public function delete(DockerNetwork $network): void
        {
            $network->delete();
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertSee('Delete network')
        ->call('deleteNetwork', $network->id, 'password')
        ->assertDispatched('success');

    expect(DockerNetwork::whereKey($network->id)->exists())->toBeFalse();
});

it('delete shows manager error', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function delete(DockerNetwork $network): void
        {
            throw new DockerNetworkDeletionException('This network cannot be permanently deleted because 1 container(s) are connected.');
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('deleteNetwork', $network->id, 'password')
        ->assertReturned('This network cannot be permanently deleted because 1 container(s) are connected.')
        ->assertDispatched('error');
});

it('shows the same friendly connected containers error from both deletion entrypoints', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    createStandaloneDestinationForNetwork($server);
    $message = 'This network cannot be permanently deleted because 1 container(s) are connected.';

    app()->instance(DockerNetworkManager::class, new class($message) extends DockerNetworkManager
    {
        public function __construct(private readonly string $message) {}

        public function deleteWithDestination(DockerNetwork $network): void
        {
            throw new DockerNetworkDeletionException($this->message);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('removeFromDestinations', $network->id, '', ['deleteNetwork'])
        ->assertReturned($message)
        ->assertDispatched('error');

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('deleteNetwork', $network->id, 'password')
        ->assertReturned($message)
        ->assertDispatched('error');

    expect($message)->not->toContain('has_connected_containers');
});
