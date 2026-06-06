<?php

use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkSourceType;
use App\Enums\NetworkAttachmentStatus;
use App\Models\Application;
use App\Models\DockerNetwork;
use App\Models\Environment;
use App\Models\NetworkAttachment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\Docker\NetworkAttachableResolver;
use App\Services\Docker\ResourceNetworkReconciler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'resource-reconcile-'.fake()->uuid(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'uuid' => 'resource-app',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
});

it('creates runtime discovered attachments for connected but unpersisted networks', function () {
    $reconciler = fakeResourceNetworkReconciler(
        $this->server,
        ['id' => 'container-id', 'name' => 'api-container'],
        ['resource-app' => ['Aliases' => ['api-container', 'resource-app']]],
    );

    $attachments = $reconciler->reconcile($this->application);
    $attachment = $attachments->first();
    $network = DockerNetwork::query()->where('docker_network_name', 'resource-app')->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->is_runtime_discovered)->toBeTrue()
        ->and($attachment->is_managed)->toBeFalse()
        ->and($attachment->status)->toBe(NetworkAttachmentStatus::Attached)
        ->and($attachment->container_name)->toBe('api-container')
        ->and($network)->not->toBeNull()
        ->and($network->managed_by_coolify)->toBeTrue()
        ->and($network->source_type)->toBe(DockerNetworkSourceType::ComposeStackDefault)
        ->and($network->network_role)->toBe(DockerNetworkRole::ResourceStack);
});

it('keeps configured attachments distinct from runtime state during reconciliation', function () {
    $network = DockerNetwork::create([
        'server_id' => $this->server->id,
        'display_name' => 'Backend Network',
        'docker_network_name' => 'backend-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_active' => true,
    ]);

    $attached = NetworkAttachment::create([
        'server_id' => $this->server->id,
        'docker_network_id' => $network->id,
        'attachable_type' => Application::class,
        'attachable_id' => $this->application->id,
        'resource_type' => 'application',
        'resource_id' => $this->application->id,
        'is_managed' => true,
        'status' => NetworkAttachmentStatus::Attached,
    ]);

    $desired = NetworkAttachment::create([
        'server_id' => $this->server->id,
        'docker_network_id' => $network->id,
        'attachable_type' => Application::class,
        'attachable_id' => $this->application->id,
        'resource_type' => 'application',
        'resource_id' => $this->application->id,
        'service_name' => 'secondary',
        'is_managed' => true,
        'status' => NetworkAttachmentStatus::Desired,
    ]);

    $reconciler = fakeResourceNetworkReconciler(
        $this->server,
        ['id' => 'container-id', 'name' => 'api-container'],
        [],
    );

    $reconciler->reconcile($this->application);

    expect($attached->refresh()->status)->toBe(NetworkAttachmentStatus::Detached)
        ->and($desired->refresh()->status)->toBe(NetworkAttachmentStatus::Desired);
});

function fakeResourceNetworkReconciler(Server $server, ?array $resolvedContainer, array $runtimeNetworks): ResourceNetworkReconciler
{
    return new ResourceNetworkReconciler(
        resolver: new class($server, $resolvedContainer) extends NetworkAttachableResolver
        {
            public function __construct(private Server $server, private ?array $resolvedContainer) {}

            public function resolveServer(Model $resource): ?Server
            {
                return $this->server;
            }

            public function resolveRuntimeContainer(Model $resource, ?NetworkAttachment $attachment = null): ?array
            {
                return $this->resolvedContainer;
            }
        },
        executor: function (Server $server, array $command) use ($resolvedContainer, $runtimeNetworks): ?string {
            if (str_starts_with($command[0], 'docker inspect')) {
                return json_encode([[
                    'Id' => data_get($resolvedContainer, 'id', 'container-id'),
                    'Name' => '/'.data_get($resolvedContainer, 'name', 'api-container'),
                    'NetworkSettings' => [
                        'Networks' => $runtimeNetworks,
                    ],
                ]]);
            }

            if (str_starts_with($command[0], 'docker network inspect')) {
                preg_match("/docker network inspect '([^']+)'/", $command[0], $matches);
                $networkName = $matches[1] ?? null;

                if ($networkName === null) {
                    return null;
                }

                return json_encode([[
                    'Name' => $networkName,
                    'Id' => $networkName.'-id',
                    'Driver' => 'bridge',
                    'Scope' => 'local',
                    'Attachable' => true,
                    'IPAM' => ['Config' => []],
                    'Containers' => [],
                ]]);
            }

            return null;
        },
    );
}
