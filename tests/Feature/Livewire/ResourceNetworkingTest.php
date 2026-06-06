<?php

use App\Enums\NetworkAttachmentStatus;
use App\Livewire\Project\Shared\Networking;
use App\Models\Application;
use App\Models\DockerNetwork;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\NetworkAttachment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Docker\DockerNetworkCatalogRefresher;
use App\Services\Docker\NetworkAttachableResolver;
use App\Services\Docker\ResourceNetworkPlanner;
use App\Services\Docker\ResourceNetworkReconciler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'resource-networking-'.fake()->uuid(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $this->network = DockerNetwork::create([
        'server_id' => $this->server->id,
        'display_name' => 'Backend Network',
        'docker_network_name' => 'backend-net',
        'driver' => 'bridge',
        'scope' => 'local',
        'managed_by_coolify' => true,
        'external' => false,
        'is_active' => true,
    ]);

    app()->instance(ResourceNetworkReconciler::class, new class extends ResourceNetworkReconciler
    {
        public function __construct() {}

        public function reconcile(Model $resource): Collection
        {
            return NetworkAttachment::query()
                ->with('dockerNetwork')
                ->where('attachable_type', $resource::class)
                ->where('attachable_id', $resource->id)
                ->get();
        }
    });
});

it('does not block initial render while background refresh is pending', function () {
    $calls = 0;
    app()->instance(DockerNetworkCatalogRefresher::class, new class($calls) extends DockerNetworkCatalogRefresher
    {
        public function __construct(private int &$calls) {}

        public function refresh(Server $server, bool $force = false): Collection
        {
            $this->calls++;

            return collect([
                'found' => 1,
                'created' => 0,
                'updated' => 0,
                'removed' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Networking::class, ['resource' => $this->application])
        ->assertSuccessful()
        ->assertSet('refreshWarning', null)
        ->assertSee('Refreshing networks...');

    expect($calls)->toBe(0);
});

it('refreshes available networks in background when requested', function () {
    $calls = 0;
    app()->instance(DockerNetworkCatalogRefresher::class, new class($calls) extends DockerNetworkCatalogRefresher
    {
        public function __construct(private int &$calls) {}

        public function refresh(Server $server, bool $force = false): Collection
        {
            $this->calls++;

            return collect([
                'found' => 1,
                'created' => 0,
                'updated' => 0,
                'removed' => 0,
                'errors' => [],
                'networks' => collect(),
            ]);
        }
    });

    Livewire::test(Networking::class, ['resource' => $this->application])
        ->call('refreshNetworksInBackground')
        ->assertSet('refreshWarning', null);

    expect($calls)->toBe(1);
});

it('shows last known networks when automatic refresh fails', function () {
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

    Livewire::test(Networking::class, ['resource' => $this->application])
        ->call('refreshNetworksInBackground')
        ->assertSee('Could not refresh Docker networks. Showing last known state.')
        ->call('showConnectNetworkForm')
        ->assertSee('Backend Network');
});

it('renders application networking and lists same-server networks only', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    DockerNetwork::create([
        'server_id' => $otherServer->id,
        'display_name' => 'Other Network',
        'docker_network_name' => 'other-net',
    ]);

    $this->application->settings()->update(['managed_network_mode' => true]);
    NetworkAttachment::create([
        'server_id' => $this->server->id,
        'docker_network_id' => $this->network->id,
        'attachable_type' => Application::class,
        'attachable_id' => $this->application->id,
        'resource_type' => 'application',
        'resource_id' => $this->application->id,
        'is_required' => true,
        'is_primary' => true,
        'is_managed' => true,
        'is_runtime_discovered' => false,
        'status' => NetworkAttachmentStatus::Attached,
    ]);

    Livewire::test(Networking::class, ['resource' => $this->application])
        ->assertSuccessful()
        ->assertSee('Network Connections')
        ->assertSee('Connect network')
        ->assertDontSee('Other Network')
        ->assertDontSee('Managed networking mode')
        ->assertDontSee('Dry run')
        ->assertDontSee('Apply networking')
        ->assertDontSee('Desired Network Attachments')
        ->assertDontSee('Container name')
        ->assertDontSee('Target override')
        ->assertDontSee('Preview execution')
        ->call('showConnectNetworkForm')
        ->assertSee('Backend Network')
        ->assertSee('backend-net')
        ->assertSee('Created by Coolify')
        ->assertSee('Configured')
        ->assertSee('Connected')
        ->assertSee('Primary')
        ->assertSee('Required')
        ->assertDontSee('Other Network');
});

it('connects a network through the simplified flow', function () {
    app()->instance(NetworkAttachableResolver::class, new class($this->server) extends NetworkAttachableResolver
    {
        public function __construct(private Server $server) {}

        public function resolveServer(Model $resource): ?Server
        {
            return $this->server;
        }
    });

    app()->instance(ResourceNetworkPlanner::class, new class extends ResourceNetworkPlanner
    {
        public function __construct() {}

        public function connect(NetworkAttachment $attachment): array
        {
            $attachment->update([
                'status' => NetworkAttachmentStatus::Attached,
                'last_checked_at' => now(),
                'last_error' => null,
                'container_name' => 'api-container',
            ]);

            return [
                'attachment_id' => $attachment->id,
                'status' => 'attached',
                'success' => true,
                'message' => 'Attached to runtime network.',
                'error' => null,
                'action' => 'connect',
                'container_name' => 'api-container',
                'container_id' => null,
                'docker_network_name' => $attachment->dockerNetwork?->docker_network_name,
                'aliases' => $attachment->aliases ?? [],
                'reason' => 'Attached to runtime network.',
                'command_preview' => null,
                'blocking' => false,
            ];
        }
    });

    Livewire::test(Networking::class, ['resource' => $this->application])
        ->call('showConnectNetworkForm')
        ->set('selectedNetworkId', $this->network->id)
        ->set('aliases', 'api, backend')
        ->set('isPrimary', true)
        ->set('isRequired', true)
        ->call('connectNetwork')
        ->assertDispatched('success')
        ->assertSee('api, backend')
        ->assertSee('Connected')
        ->assertSee('Disconnect')
        ->assertDontSee('Connect</button>', false);

    $attachment = NetworkAttachment::first();

    expect($attachment->status)->toBe(NetworkAttachmentStatus::Attached)
        ->and($attachment->aliases)->toBe(['api', 'backend'])
        ->and($attachment->is_primary)->toBeTrue()
        ->and($attachment->is_required)->toBeTrue()
        ->and($this->application->settings()->first()->managed_network_mode)->toBeTrue()
        ->and((bool) $this->application->settings()->first()->connect_to_docker_network)->toBeFalse();
});

it('edits and removes network configuration from UI', function () {
    $attachment = NetworkAttachment::create([
        'server_id' => $this->server->id,
        'docker_network_id' => $this->network->id,
        'attachable_type' => Application::class,
        'attachable_id' => $this->application->id,
        'resource_type' => 'application',
        'resource_id' => $this->application->id,
        'aliases' => ['api', 'backend'],
        'is_primary' => true,
        'is_required' => true,
        'is_managed' => true,
        'is_runtime_discovered' => false,
        'status' => NetworkAttachmentStatus::Desired,
    ]);

    Livewire::test(Networking::class, ['resource' => $this->application])
        ->assertSee('api, backend')
        ->assertSee('Pending')
        ->assertSee('Remove Network Configuration?')
        ->assertSee('Please confirm by entering the Docker network name below')
        ->assertDontSee('confirm(')
        ->call('editAttachment', $attachment->id)
        ->set('aliases', 'api')
        ->set('isRequired', false)
        ->call('updateAttachment')
        ->assertDispatched('success')
        ->assertSee('api')
        ->call('removeAttachment', NetworkAttachment::first()->id)
        ->assertDispatched('success')
        ->assertSee('This resource is not connected to any additional Docker networks.');

    expect(NetworkAttachment::count())->toBe(0);
});

it('shows passive warnings for inactive network and unknown target', function () {
    $this->network->update(['is_active' => false]);
    NetworkAttachment::create([
        'server_id' => $this->server->id,
        'docker_network_id' => $this->network->id,
        'attachable_type' => Application::class,
        'attachable_id' => $this->application->id,
        'resource_type' => 'application',
        'resource_id' => $this->application->id,
        'is_required' => true,
        'is_managed' => true,
        'is_runtime_discovered' => false,
        'status' => 'missing_container',
    ]);

    Livewire::test(Networking::class, ['resource' => $this->application])
        ->assertSee('This network is inactive')
        ->assertSee('Could not find the running container for this resource.')
        ->assertSee('This connection is required, but the container was not found.')
        ->assertSee('Container not found');
});

it('shows runtime discovered connected networks after reconciliation', function () {
    app()->instance(ResourceNetworkReconciler::class, new class extends ResourceNetworkReconciler
    {
        public function __construct() {}

        public function reconcile(Model $resource): Collection
        {
            $network = DockerNetwork::query()->firstOrFail();

            NetworkAttachment::firstOrCreate([
                'attachable_type' => $resource::class,
                'attachable_id' => $resource->id,
                'docker_network_id' => $network->id,
                'server_id' => $network->server_id,
            ], [
                'resource_type' => 'application',
                'resource_id' => $resource->id,
                'is_managed' => false,
                'is_runtime_discovered' => true,
                'status' => NetworkAttachmentStatus::Attached,
                'container_name' => 'api-container',
            ]);

            return NetworkAttachment::query()->with('dockerNetwork')->get();
        }
    });

    Livewire::test(Networking::class, ['resource' => $this->application])
        ->call('refreshNetworksInBackground')
        ->assertSee('Runtime only')
        ->assertSee('Connected');
});

it('does not render removed container targeting and preview controls', function () {
    Livewire::test(Networking::class, ['resource' => $this->application])
        ->assertDontSee('Target override')
        ->assertDontSee('Preview execution')
        ->assertDontSee('Dry run')
        ->assertDontSee('Container target')
        ->assertDontSee('Container name');
});
