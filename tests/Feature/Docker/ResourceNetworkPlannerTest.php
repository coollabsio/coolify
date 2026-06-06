<?php

use App\Enums\NetworkAttachmentStatus;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\DockerNetwork;
use App\Models\Environment;
use App\Models\NetworkAttachment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\Docker\NetworkAttachableResolver;
use App\Services\Docker\ResourceNetworkPlanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'planner-'.fake()->uuid(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'uuid' => 'app-uuid',
    ]);
    $this->application->setRelation('settings', tap($this->application->settings, function (ApplicationSetting $settings): void {
        $settings->managed_network_mode = true;
        $settings->save();
    }));
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
    $this->attachment = NetworkAttachment::create([
        'server_id' => $this->server->id,
        'docker_network_id' => $this->network->id,
        'attachable_type' => Application::class,
        'attachable_id' => $this->application->id,
        'resource_type' => 'application',
        'resource_id' => $this->application->id,
        'aliases' => ['api', 'backend'],
        'is_required' => true,
        'is_managed' => true,
        'is_runtime_discovered' => false,
        'status' => NetworkAttachmentStatus::Desired,
    ]);
});

function connectPlanner(Server $server, ?array $resolvedContainer): ResourceNetworkPlanner
{
    $connected = false;

    return new ResourceNetworkPlanner(
        resolver: fakePlannerResolver($server, $resolvedContainer),
        executor: function (Server $s, array $command) use (&$connected, $resolvedContainer): ?string {
            if (str_starts_with($command[0], 'docker network inspect')) {
                return json_encode([['Name' => 'backend-net']]);
            }

            if (str_starts_with($command[0], 'docker network connect')) {
                $connected = true;

                return '';
            }

            if (str_starts_with($command[0], 'docker inspect')) {
                return json_encode([[
                    'Id' => data_get($resolvedContainer, 'id', 'container-id'),
                    'Name' => '/'.data_get($resolvedContainer, 'name', 'api-container'),
                    'NetworkSettings' => [
                        'Networks' => $connected ? ['backend-net' => ['NetworkID' => 'net-id']] : [],
                    ],
                ]]);
            }

            return null;
        },
    );
}

it('connects by resolving runtime container and updating attachment status', function () {
    $planner = connectPlanner($this->server, ['id' => 'container-id', 'name' => 'api-container']);

    $result = $planner->connect($this->attachment);

    expect($result['success'])->toBeTrue()
        ->and($this->attachment->refresh()->status)->toBe(NetworkAttachmentStatus::Attached)
        ->and($this->attachment->container_name)->toBe('api-container')
        ->and($this->attachment->container_id)->toBe('container-id');
});

it('updates stale container ids after container recreation', function () {
    $this->attachment->update(['container_id' => 'old-id', 'container_name' => 'fixed-app-name']);
    $planner = connectPlanner($this->server, ['id' => 'new-id', 'name' => 'fixed-app-name']);

    $result = $planner->connect($this->attachment->refresh());

    expect($result['success'])->toBeTrue()
        ->and($this->attachment->refresh()->container_id)->toBe('new-id')
        ->and($this->attachment->container_name)->toBe('fixed-app-name');
});

it('fails connect when runtime container cannot be resolved', function () {
    $planner = new ResourceNetworkPlanner(
        resolver: fakePlannerResolver($this->server, null),
        executor: fn () => null,
    );

    $result = $planner->connect($this->attachment);

    expect($result['success'])->toBeFalse()
        ->and($this->attachment->refresh()->status)->toBe(NetworkAttachmentStatus::MissingContainer)
        ->and($this->attachment->last_error)->toBe('Could not find the running container for this resource.');
});

it('fails connect when selected network no longer exists', function () {
    $planner = new ResourceNetworkPlanner(
        resolver: fakePlannerResolver($this->server, ['id' => 'container-id', 'name' => 'api-container']),
        executor: fn (Server $server, array $command): ?string => str_starts_with($command[0], 'docker network inspect') ? null : 'ok',
    );

    $result = $planner->connect($this->attachment);

    expect($result['success'])->toBeFalse()
        ->and($this->attachment->refresh()->status)->toBe(NetworkAttachmentStatus::MissingNetwork);
});

it('disconnects a managed attachment and verifies detachment', function () {
    $this->attachment->update(['status' => NetworkAttachmentStatus::Attached]);
    $disconnected = false;

    $planner = new ResourceNetworkPlanner(
        resolver: fakePlannerResolver($this->server, ['id' => 'container-id', 'name' => 'api-container']),
        executor: function (Server $server, array $command) use (&$disconnected): ?string {
            if (str_starts_with($command[0], 'docker network inspect')) {
                return json_encode([['Name' => 'backend-net']]);
            }

            if (str_starts_with($command[0], 'docker network disconnect')) {
                $disconnected = true;

                return '';
            }

            if (str_starts_with($command[0], 'docker inspect')) {
                return json_encode([[
                    'Id' => 'container-id',
                    'Name' => '/api-container',
                    'NetworkSettings' => [
                        'Networks' => $disconnected ? [] : ['backend-net' => ['NetworkID' => 'network-id']],
                    ],
                ]]);
            }

            return null;
        },
    );

    $result = $planner->disconnect($this->attachment);

    expect($result['success'])->toBeTrue()
        ->and($this->attachment->refresh()->status)->toBe(NetworkAttachmentStatus::Detached);
});

it('blocks disconnect of runtime-discovered attachments', function () {
    $this->attachment->update([
        'status' => NetworkAttachmentStatus::Attached,
        'is_runtime_discovered' => true,
    ]);

    $planner = new ResourceNetworkPlanner(
        resolver: fakePlannerResolver($this->server, ['id' => 'container-id', 'name' => 'api-container']),
        executor: fn () => null,
    );

    $result = $planner->disconnect($this->attachment);

    expect($result['success'])->toBeFalse();
});

function fakePlannerResolver(Server $server, ?array $resolvedContainer): NetworkAttachableResolver
{
    return new class($server, $resolvedContainer) extends NetworkAttachableResolver
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
    };
}
