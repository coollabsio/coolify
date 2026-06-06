<?php

use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkSourceType;
use App\Exceptions\DockerNetworkDeletionException;
use App\Exceptions\DockerNetworkImportException;
use App\Livewire\Server\DockerNetworks\Index;
use App\Models\DockerNetwork;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Docker\DockerNetworkCatalogRefresher;
use App\Services\Docker\DockerNetworkManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

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
            'containers' => [],
            'raw' => ['Name' => 'backend-net'],
        ],
    ], $attributes));
}

it('renders docker networks page and lists only current server networks', function () {
    $server = createDockerNetworksUiServer();
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    createCatalogNetwork($server);
    createCatalogNetwork($otherServer, [
        'display_name' => 'Other Network',
        'docker_network_name' => 'other-net',
    ]);

    $this->get(route('server.docker-networks', ['server_uuid' => $server->uuid]))
        ->assertSuccessful()
        ->assertSeeLivewire(Index::class)
        ->assertSee('Docker Networks')
        ->assertSee('Backend Network')
        ->assertSee('Managed')
        ->assertDontSee('Docker Network Name')
        ->assertDontSee('Other Network');
});

it('renders responsive status badges for docker networks', function () {
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
        ->assertSeeHtml('border-coollabs/20 bg-coollabs/10 text-coollabs dark:border-warning/30 dark:bg-warning/20 dark:text-warning')
        ->assertSeeHtml('border-neutral-200 bg-neutral-100 text-black dark:border-coolgray-300 dark:bg-coolgray-200 dark:text-white')
        ->assertSeeHtml('border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-300');
});

it('shows empty state when no networks are cataloged', function () {
    $server = createDockerNetworksUiServer();

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->assertSee('No Docker networks known yet.')
        ->assertSee('Refresh');
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
                'marked_inactive' => 0,
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
                'marked_inactive' => 0,
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
                'marked_inactive' => 0,
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
                'marked_inactive' => 0,
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
                'marked_inactive' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('showCreateForm', true)
        ->set('createDisplayName', 'Created Network')
        ->set('createDriver', 'bridge')
        ->call('createNetwork')
        ->assertSee('Created Network')
        ->assertDispatched('success');

    expect($refreshCalls)->toBe(1);
});

it('validates create display name', function () {
    $server = createDockerNetworksUiServer();

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('createDisplayName', '')
        ->call('createNetwork')
        ->assertHasErrors(['createDisplayName' => 'required']);
});

it('imports network through manager', function () {
    $server = createDockerNetworksUiServer();
    $refreshCalls = 0;
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function import(Server $server, string $networkName, ?string $displayName = null): DockerNetwork
        {
            return createCatalogNetwork($server, [
                'display_name' => $displayName ?: $networkName,
                'docker_network_name' => $networkName,
                'managed_by_coolify' => true,
                'external' => false,
                'source_type' => DockerNetworkSourceType::ImportedExternal->value,
                'network_role' => DockerNetworkRole::ManagedCustom->value,
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
                'created' => 0,
                'updated' => 1,
                'marked_inactive' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('importNetworkName', 'external-net')
        ->set('importDisplayName', 'External Network')
        ->call('importNetwork')
        ->assertSee('External Network')
        ->assertSee('Managed');

    expect($refreshCalls)->toBe(1);
});

it('shows import errors', function () {
    $server = createDockerNetworksUiServer();
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function import(Server $server, string $networkName, ?string $displayName = null): DockerNetwork
        {
            throw new DockerNetworkImportException('Docker network could not be inspected.');
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('importNetworkName', 'missing-net')
        ->call('importNetwork')
        ->assertDispatched('error');
});

it('renders import form warning for reserved system networks', function () {
    $server = createDockerNetworksUiServer();

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->set('showImportForm', true)
        ->assertSee('Reserved system networks like')
        ->assertSee('bridge')
        ->assertSee('host')
        ->assertSee('coolify');
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

it('inspect panel shows metadata and containers', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server, [
        'last_inspect_data' => [
            'docker_id' => 'network-id',
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
        ->assertSee('network-id')
        ->assertSee('api')
        ->assertSee('172.30.10.2/24')
        ->assertSee('Raw inspect data');
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

it('delete calls manager and shows inactive network', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    $refreshCalls = 0;
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function delete(DockerNetwork $network): void
        {
            $network->update(['is_active' => false]);
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
                'marked_inactive' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('deleteNetwork', $network->id)
        ->assertSee('Inactive')
        ->assertDispatched('success');

    expect($refreshCalls)->toBe(1);
});

it('delete shows manager error', function () {
    $server = createDockerNetworksUiServer();
    $network = createCatalogNetwork($server);
    app()->instance(DockerNetworkManager::class, new class extends DockerNetworkManager
    {
        public function __construct() {}

        public function delete(DockerNetwork $network): void
        {
            throw new DockerNetworkDeletionException('has_connected_containers');
        }
    });

    Livewire::test(Index::class, ['server_uuid' => $server->uuid])
        ->call('deleteNetwork', $network->id)
        ->assertDispatched('error');
});
