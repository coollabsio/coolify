<?php

use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Enums\NodeContainerManagementState;
use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Livewire\Project\ClusterApplication\Show as ClusterApplicationShow;
use App\Livewire\Project\New\DockerImage;
use App\Livewire\Project\New\Select;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->team->update(['show_boarding' => false]);
    Cache::flush();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->cluster = NodeCluster::factory()->create([
        'team_id' => $this->team->id,
        'created_by_user_id' => $this->user->id,
        'network_status' => 'active',
    ]);
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'node_cluster_id' => $this->cluster->id,
        'private_key_id' => $key->id,
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => healthyNodeResourceMetadata(),
    ]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('creates a project and environment linked workload for a cluster Docker image', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project,
        $this->environment,
        $this->cluster,
        'docker.io/library/nginx:stable',
        $this->user,
    );

    $workload = $deployment['workload'];

    expect($workload->team_id)->toBe($this->team->id)
        ->and($workload->project_id)->toBe($this->project->id)
        ->and($workload->environment_id)->toBe($this->environment->id)
        ->and($workload->nodes()->sole()->is($this->node))->toBeTrue()
        ->and($deployment['revision']->image)->toBe('docker.io/library/nginx:stable')
        ->and($deployment['operation']->node_id)->toBe($this->node->id);
});

it('deploys a cluster Docker image to the selected Node', function () {
    $selectedNode = Node::factory()->create([
        'team_id' => $this->team->id,
        'node_cluster_id' => $this->cluster->id,
        'private_key_id' => $this->node->private_key_id,
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => healthyNodeResourceMetadata(),
    ]);

    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project,
        $this->environment,
        $this->cluster,
        'nginx:latest',
        $this->user,
        $selectedNode,
    );

    expect($deployment['workload']->nodes()->sole()->is($selectedNode))->toBeTrue();
});

it('skips a pressured Node when it selects a deployment target', function () {
    $this->node->update(['metadata' => [...$this->node->metadata, 'memory_used_bytes' => 950]]);
    $healthyNode = Node::factory()->create([
        'team_id' => $this->team->id,
        'node_cluster_id' => $this->cluster->id,
        'private_key_id' => $this->node->private_key_id,
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => healthyNodeResourceMetadata(),
    ]);

    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );

    expect($deployment['workload']->nodes()->sole()->is($healthyNode))->toBeTrue();
});

it('returns the pressure reason for an explicitly selected Node before creating records', function () {
    $this->node->update(['metadata' => [...$this->node->metadata, 'disk_available_bytes' => 50]]);

    expect(fn () => CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user, $this->node,
    ))->toThrow(DomainException::class, 'disk pressure')
        ->and(NodeWorkload::query()->count())->toBe(0);
});

it('rejects a cluster from another team', function () {
    $otherCluster = NodeCluster::factory()->create();

    expect(fn () => CreateClusterDockerImageWorkload::run(
        $this->project,
        $this->environment,
        $otherCluster,
        'nginx:latest',
        $this->user,
    ))->toThrow(RuntimeException::class, 'The cluster does not belong to this project team.');

    expect(NodeWorkload::query()->count())->toBe(0);
});

it('rejects a team member who cannot deploy resources', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);

    expect(fn () => CreateClusterDockerImageWorkload::run(
        $this->project,
        $this->environment,
        $this->cluster,
        'nginx:latest',
        $member,
    ))->toThrow(RuntimeException::class, 'The user cannot deploy resources for this project team.');

    expect(NodeWorkload::query()->count())->toBe(0);
});

it('requires an available workload node in the selected cluster', function () {
    $this->node->update(['is_usable' => false]);

    expect(fn () => CreateClusterDockerImageWorkload::run(
        $this->project,
        $this->environment,
        $this->cluster,
        'nginx:latest',
        $this->user,
    ))->toThrow(RuntimeException::class, 'The cluster has no workload Node that can accept a deployment.');
});

function healthyNodeResourceMetadata(): array
{
    return [
        'cpu_usage_percent' => 10,
        'memory_bytes' => 1_000,
        'memory_used_bytes' => 100,
        'disk_total_bytes' => 1_000,
        'disk_available_bytes' => 900,
        'collected_at' => now()->toIso8601String(),
    ];
}

it('creates and queues a cluster Docker image from the environment resource flow', function () {
    Queue::fake();
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];

    Livewire::withUrlParams(['cluster' => $this->cluster->uuid])
        ->test(DockerImage::class, $routeParameters)
        ->set('parameters', $routeParameters)
        ->set('imageName', 'nginx')
        ->set('imageTag', 'stable')
        ->call('submit')
        ->assertRedirect(route('project.cluster-application.show', [
            ...$routeParameters,
            'workload_uuid' => NodeWorkload::query()->sole()->uuid,
        ]));

    $workload = NodeWorkload::query()->sole();
    expect($workload->project_id)->toBe($this->project->id)
        ->and($workload->environment_id)->toBe($this->environment->id)
        ->and($workload->revisions()->sole()->image)->toBe('docker.io/library/nginx:stable');
    Queue::assertPushed(DeployNodeWorkloadJob::class);
});

it('shows the cluster application in its project and environment', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->assertSee($deployment['workload']->name)
        ->assertSee('nginx:latest')
        ->assertSee($this->cluster->name)
        ->assertSee($this->node->name)
        ->assertSee('General')
        ->assertSee('Deployment Logs')
        ->assertSee('Internal mesh')
        ->assertSee('External')
        ->assertSee('Not available yet')
        ->assertSee($deployment['workload']->internal_dns_name.'.default.coolify.internal')
        ->assertSeeHtml('application-settings-workspace')
        ->assertSeeHtml('cluster-application-mobile-actions')
        ->assertSeeHtml('cluster-application-desktop-actions')
        ->assertDontSeeHtml('mb-5 hidden min-w-0 items-center');

    $this->get(route('project.cluster-application.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ]))
        ->assertOk()
        ->assertSee($deployment['workload']->name)
        ->assertSee(route('project.cluster-application.show', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'workload_uuid' => $deployment['workload']->uuid,
        ]), false);
});

it('lets an administrator create a new revision with resource settings', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $original = $deployment['revision'];

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->assertSee('Resource limits')
        ->set('cpuLimit', '2.5')
        ->set('cpuReservation', '1.25')
        ->set('memoryLimitMb', '1024')
        ->set('memoryReservationMb', '512')
        ->call('saveResources')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $latest = $deployment['workload']->revisions()->latest('id')->firstOrFail();
    expect($deployment['workload']->revisions()->count())->toBe(2)
        ->and($original->fresh()->configuration)->not->toHaveKey('resources')
        ->and($latest->configuration['resources'])->toBe([
            'cpu_limit' => 2.5,
            'cpu_reservation' => 1.25,
            'memory_limit_bytes' => 1_073_741_824,
            'memory_reservation_bytes' => 536_870_912,
        ]);
});

it('validates resource reservations against their limits', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->set('cpuLimit', '1')
        ->set('cpuReservation', '2')
        ->set('memoryLimitMb', '256')
        ->set('memoryReservationMb', '512')
        ->call('saveResources')
        ->assertHasErrors(['cpuReservation', 'memoryReservationMb']);
});

it('forbids members from changing application resource settings', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->set('cpuLimit', '2')
        ->call('saveResources')
        ->assertForbidden();

    expect($deployment['workload']->revisions()->count())->toBe(1);
});

it('offers the legacy application lifecycle actions for cluster applications', function () {
    Queue::fake();
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['operation']->update(['status' => NodeOperationStatus::SUCCEEDED]);
    $this->node->update(['metadata' => ['container_inventory_observed_at' => now()->toIso8601String()]]);
    NodeContainer::factory()->create([
        'node_id' => $this->node->id,
        'node_workload_id' => $deployment['workload']->id,
        'node_workload_revision_id' => $deployment['revision']->id,
        'state' => 'running',
        'management_state' => NodeContainerManagementState::MANAGED,
        'is_managed' => true,
    ]);

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->assertSee('Restart')
        ->assertSee('Stop')
        ->call('manage', 'restart')
        ->assertDispatched('success', 'Restart command queued.');

    $operation = $deployment['workload']->operations()->latest('id')->firstOrFail();
    expect($operation->command_type)->toBe('workload.lifecycle.v1')
        ->and(data_get($operation->request, 'action'))->toBe('restart');
    Queue::assertPushed(ManageNodeWorkloadJob::class, fn ($job) => $job->operationId === $operation->id);
});

it('offers ready clusters after Docker image is selected from New resource', function () {
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];

    $component = new Select;
    $component->parameters = $routeParameters;
    $component->loadServers();
    $component->setType('docker-image');
    $response = $component->setCluster($this->cluster->uuid);

    expect($component->current_step)->toBe('targets')
        ->and($component->clusters->modelKeys())->toBe([$this->cluster->id])
        ->and($response->getTargetUrl())->toBe(route('project.resource.create', [
            ...$routeParameters,
            'type' => 'docker-image',
            'cluster' => $this->cluster->uuid,
        ]));
});

it('allows a specific Node to be selected from New resource', function () {
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];

    $component = new Select;
    $component->parameters = $routeParameters;
    $component->loadServers();
    $component->setType('docker-image');
    $response = $component->setNode($this->node->uuid);

    expect($response->getTargetUrl())->toBe(route('project.resource.create', [
        ...$routeParameters,
        'type' => 'docker-image',
        'node' => $this->node->uuid,
    ]));
});

it('always shows cluster selection on the Docker image form after a server shortcut', function () {
    Queue::fake();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'cluster-target-test',
    ]);

    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];

    $component = Livewire::withUrlParams(['destination' => $destination->uuid])
        ->test(DockerImage::class)
        ->assertSet('deploymentTarget', 'destination:'.$destination->uuid)
        ->assertSee('Deployment target')
        ->assertSee($this->cluster->name)
        ->set('parameters', $routeParameters)
        ->set('deploymentTarget', 'cluster:'.$this->cluster->uuid)
        ->set('imageName', 'nginx')
        ->call('submit');

    $workload = NodeWorkload::query()->sole();
    $component->assertRedirect(route('project.cluster-application.show', [
        ...$routeParameters,
        'workload_uuid' => $workload->uuid,
    ]));
    expect($workload->nodes()->sole()->is($this->node))->toBeTrue();
    Queue::assertPushed(DeployNodeWorkloadJob::class);
});

it('shows individual Nodes as Docker image deployment targets', function () {
    Livewire::test(DockerImage::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertSee($this->node->name)
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ])
        ->set('deploymentTarget', 'node:'.$this->node->uuid)
        ->set('imageName', 'nginx')
        ->call('submit');

    expect(NodeWorkload::query()->sole()->nodes()->sole()->is($this->node))->toBeTrue();
});

it('shows cluster workloads in their environment resource list', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project,
        $this->environment,
        $this->cluster,
        'nginx:latest',
        $this->user,
    );

    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]))
        ->assertOk()
        ->assertSee($deployment['workload']->name)
        ->assertSee('Cluster application');
});
