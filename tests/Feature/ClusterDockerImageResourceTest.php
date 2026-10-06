<?php

use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\UpdateNodeWorkloadConfiguration;
use App\Enums\NodeContainerManagementState;
use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Livewire\Project\ClusterApplication\Show as ClusterApplicationShow;
use App\Livewire\Project\New\DockerImage;
use App\Livewire\Project\New\Select;
use App\Livewire\Project\Shared\EnvironmentVariable\All as EnvironmentVariableAll;
use App\Livewire\Project\Shared\EnvironmentVariable\Show as EnvironmentVariableShow;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportRedirects\Redirector;
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
    ))->toThrow(RuntimeException::class, 'The cluster has no workload server that can accept a deployment.');
});

it('deploys only to converged Nodes of a degraded cluster network', function () {
    $this->cluster->update(['network_status' => 'degraded', 'desired_revision' => 3]);
    $this->node->update(['network_applied_revision' => 2, 'network_status' => 'pending', 'corrosion_status' => 'converged']);
    $convergedNode = Node::factory()->create([
        'team_id' => $this->team->id,
        'node_cluster_id' => $this->cluster->id,
        'private_key_id' => $this->node->private_key_id,
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => healthyNodeResourceMetadata(),
        'network_applied_revision' => 3,
        'network_status' => 'converged',
        'corrosion_status' => 'converged',
    ]);

    expect(fn () => CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user, $this->node,
    ))->toThrow(RuntimeException::class, 'The selected server is not available in this cluster.');

    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster->refresh(), 'nginx:latest', $this->user,
    );

    expect($deployment['workload']->nodes()->sole()->is($convergedNode))->toBeTrue();

    $component = new Select;
    $component->parameters = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid];
    $component->loadServers();
    expect($component->clusters->modelKeys())->toBe([$this->cluster->id])
        ->and($component->clusters->sole()->nodes->modelKeys())->toBe([$convergedNode->id]);
});

it('rejects deployments while the cluster network failed', function () {
    $this->cluster->update(['network_status' => 'error']);

    expect(fn () => CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    ))->toThrow(RuntimeException::class, 'The cluster network is not ready.');
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
        ->assertRedirect(route('project.cluster-application.deployment.show', [
            ...$routeParameters,
            'workload_uuid' => NodeWorkload::query()->sole()->uuid,
            'deployment_uuid' => NodeOperation::query()->where('command_type', 'workload.deploy.v1')->sole()->uuid,
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
        ->assertSee('Resource Limits')
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
        ->call('restart')
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
        'server' => $this->node->uuid,
    ]));
});

it('accepts Livewire redirects for both cluster and Node selection', function () {
    $component = new Select;
    $component->parameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];

    $originalRedirector = app('redirect');
    app()->instance('redirect', app(Redirector::class)->component($component));

    try {
        expect($component->setCluster($this->cluster->uuid))->toBeInstanceOf(Redirector::class);
        expect($component->setNode($this->node->uuid))->toBeInstanceOf(Redirector::class);
    } finally {
        app()->instance('redirect', $originalRedirector);
    }
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
    $component->assertRedirect(route('project.cluster-application.deployment.show', [
        ...$routeParameters,
        'workload_uuid' => $workload->uuid,
        'deployment_uuid' => $workload->operations()->where('command_type', 'workload.deploy.v1')->sole()->uuid,
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

it('opens the Docker image form for a specific Node target', function () {
    $this->get(route('project.resource.create', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'type' => 'docker-image',
        'server' => $this->node->uuid,
    ]))
        ->assertOk()
        ->assertSeeLivewire(DockerImage::class)
        ->assertDontSeeLivewire(Select::class);
});

it('creates a new revision when resource settings return to an earlier value', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $component = Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ]);

    foreach (['1', '2', '1'] as $cpuLimit) {
        $component->set('cpuLimit', $cpuLimit)->call('saveResources')->assertHasNoErrors();
    }

    $revisions = $deployment['workload']->revisions()->orderBy('id')->get();
    expect($revisions)->toHaveCount(4)
        ->and($revisions[3]->configuration['resources'])->toEqual(['cpu_limit' => 1])
        ->and($revisions[3]->configuration_hash)->toBe($revisions[1]->configuration_hash);
});

it('saves a start command and keeps the environment of the current revision', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    UpdateNodeWorkloadConfiguration::run($deployment['workload'], [], ['APP_ENV' => 'production']);

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->set('startCommand', 'nginx -g "daemon off;"')
        ->call('saveConfiguration')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged')
        ->assertSet('startCommand', 'nginx -g "daemon off;"');

    $latest = $deployment['workload']->revisions()->latest('id')->firstOrFail();
    expect($deployment['workload']->revisions()->count())->toBe(3)
        ->and($latest->configuration)->toBe([
            'restart_policy' => 'unless-stopped',
            'command' => ['nginx', '-g', 'daemon off;'],
        ])
        ->and($latest->environment)->toBe(['APP_ENV' => 'production']);
});

it('records resolved runtime environment variables in a new encrypted revision on deploy', function () {
    Queue::fake();
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['operation']->update(['status' => NodeOperationStatus::SUCCEEDED]);
    $workload = $deployment['workload'];
    SharedEnvironmentVariable::query()->create([
        'key' => 'DB_HOST',
        'value' => 'db.internal',
        'type' => 'project',
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
    ]);
    $workload->environment_variables()->createMany([
        ['key' => 'SECRET', 'value' => 's3cret value'],
        ['key' => 'APP_ENV', 'value' => 'production'],
        ['key' => 'DATABASE_HOST', 'value' => '{{project.DB_HOST}}'],
        ['key' => 'BUILD_ONLY', 'value' => 'not-at-runtime', 'is_runtime' => false],
    ]);

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $workload->uuid,
    ])->call('deploy');

    $latest = $workload->revisions()->latest('id')->firstOrFail();
    $rawEnvironment = DB::table('node_workload_revisions')->where('id', $latest->id)->value('environment');
    expect($workload->revisions()->count())->toBe(2)
        ->and($latest->environment)->toBe([
            'APP_ENV' => 'production',
            'DATABASE_HOST' => 'db.internal',
            'SECRET' => 's3cret value',
        ])
        ->and($rawEnvironment)->not->toContain('s3cret')
        ->and($latest->toArray())->not->toHaveKey('environment')
        ->and($workload->operations()->latest('id')->firstOrFail()->node_workload_revision_id)->toBe($latest->id);

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $workload->uuid,
    ])->call('deploy');
    expect($workload->revisions()->count())->toBe(2);
});

it('does not deploy environment variable names that Podman cannot use', function () {
    Queue::fake();
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['operation']->update(['status' => NodeOperationStatus::SUCCEEDED]);
    $deployment['workload']->environment_variables()->create(['key' => 'app.name', 'value' => 'demo']);

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->call('deploy')
        ->assertDispatched('error')
        ->assertNoRedirect();

    expect($deployment['workload']->operations()->count())->toBe(1)
        ->and($deployment['workload']->revisions()->count())->toBe(1);
    Queue::assertNotPushed(DeployNodeWorkloadJob::class);
});

it('warns about pending changes until the latest configuration is deployed', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $workload = $deployment['workload'];
    expect($workload->isConfigurationChanged())->toBeFalse();

    $deployment['operation']->update(['status' => NodeOperationStatus::SUCCEEDED]);
    expect($workload->fresh()->isConfigurationChanged())->toBeFalse();

    $workload->environment_variables()->create(['key' => 'APP_ENV', 'value' => 'production']);
    expect($workload->fresh()->isConfigurationChanged())->toBeTrue();

    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $workload->uuid,
    ];
    $this->get(route('project.cluster-application.show', $routeParameters))
        ->assertOk()
        ->assertSee('Changes pending')
        ->assertSee('The latest configuration has not been applied');

    $revision = PrepareNodeWorkloadRevision::run($workload);
    NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'node_workload_id' => $workload->id,
        'node_workload_revision_id' => $revision->id,
        'command_type' => 'workload.deploy.v1',
        'status' => NodeOperationStatus::SUCCEEDED,
    ]);
    expect($workload->fresh()->isConfigurationChanged())->toBeFalse();
    $this->get(route('project.cluster-application.show', $routeParameters))
        ->assertOk()
        ->assertDontSee('Changes pending');

    UpdateNodeWorkloadConfiguration::run($workload, ['nginx']);
    expect($workload->fresh()->isConfigurationChanged())->toBeTrue();
});

it('copies environment variables from the latest revision when migrating', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    UpdateNodeWorkloadConfiguration::run($deployment['workload'], [], ['APP_ENV' => 'production', 'SECRET' => 'value']);

    $migration = require database_path('migrations/2026_09_27_183939_move_node_workload_environment_to_environment_variables.php');
    $migration->up();
    $migration->up();

    expect($deployment['workload']->environment_variables()->orderBy('order')->pluck('value', 'key')->all())
        ->toBe(['APP_ENV' => 'production', 'SECRET' => 'value'])
        ->and($deployment['workload']->fresh()->runtimeEnvironment())->toBe(['APP_ENV' => 'production', 'SECRET' => 'value']);
});

it('drops host ports from an older revision when the configuration is saved', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['workload']->createRevision('docker.io/library/nginx:latest', [
        'restart_policy' => 'unless-stopped',
        'ports' => [['host_port' => 8080, 'container_port' => 80, 'protocol' => 'tcp']],
    ]);

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->set('startCommand', 'nginx')
        ->call('saveConfiguration')
        ->assertHasNoErrors();

    expect($deployment['workload']->revisions()->latest('id')->firstOrFail()->configuration)->toBe([
        'restart_policy' => 'unless-stopped',
        'command' => ['nginx'],
    ]);
});

it('keeps the current revision when the configuration does not change', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->call('saveConfiguration')
        ->assertHasNoErrors();

    expect($deployment['workload']->revisions()->count())->toBe(1);
});

it('forbids members from changing the configuration or managing environment variables', function () {
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
        ->set('startCommand', 'nginx')
        ->call('saveConfiguration')
        ->assertForbidden();

    expect($member->can('manageEnvironment', $deployment['workload']))->toBeFalse()
        ->and($this->user->can('manageEnvironment', $deployment['workload']))->toBeTrue()
        ->and($deployment['workload']->revisions()->count())->toBe(1);
});

it('asks Sentinel for a newer image when an administrator redeploys', function () {
    Queue::fake();
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    expect(data_get($deployment['operation']->request, 'pull_policy'))->toBe('missing');
    $deployment['operation']->update(['status' => NodeOperationStatus::SUCCEEDED]);

    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ];

    $component = Livewire::test(ClusterApplicationShow::class, $routeParameters)->call('deploy');

    $operation = $deployment['workload']->operations()->latest('id')->firstOrFail();
    expect($operation->id)->not->toBe($deployment['operation']->id)
        ->and(data_get($operation->request, 'pull_policy'))->toBe('newer');
    $component->assertRedirect(route('project.cluster-application.deployment.show', [
        ...$routeParameters,
        'deployment_uuid' => $operation->uuid,
    ]));
    Queue::assertPushed(DeployNodeWorkloadJob::class);
});

it('opens the logs of the active deployment instead of queueing another one', function () {
    Queue::fake();
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ];

    Livewire::test(ClusterApplicationShow::class, $routeParameters)
        ->call('deploy')
        ->assertRedirect(route('project.cluster-application.deployment.show', [
            ...$routeParameters,
            'deployment_uuid' => $deployment['operation']->uuid,
        ]));

    expect($deployment['workload']->operations()->count())->toBe(1);
    Queue::assertNotPushed(DeployNodeWorkloadJob::class);
});

it('stays on the page when a lifecycle action blocks a new deployment', function () {
    Queue::fake();
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['operation']->update(['status' => NodeOperationStatus::SUCCEEDED]);
    NodeOperation::factory()->create([
        'node_id' => $deployment['operation']->node_id,
        'node_workload_id' => $deployment['workload']->id,
        'command_type' => 'workload.lifecycle.v1',
        'status' => NodeOperationStatus::RUNNING,
    ]);

    Livewire::test(ClusterApplicationShow::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ])
        ->call('deploy')
        ->assertNoRedirect()
        ->assertDispatched('info', 'This application already has an active operation.');
});

it('renders every cluster application section as its own page', function (string $routeName, string $section, array $expectedText) {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    UpdateNodeWorkloadConfiguration::run($deployment['workload'], ['nginx', '-g', 'daemon off;'], ['APP_ENV' => 'production']);
    $deployment['operation']->update([
        'status' => NodeOperationStatus::FAILED,
        'error' => 'Image pull failed: manifest unknown',
    ]);
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ];

    $response = $this->get(route($routeName, $routeParameters))
        ->assertOk()
        ->assertSee($deployment['workload']->name)
        ->assertSee(['Settings', 'Observe &amp; troubleshoot', 'Operations', 'Deployment Logs'], false)
        ->assertDontSee('href="#', false)
        ->assertSee('menu-item menu-item-active', false);

    foreach ([
        'project.cluster-application.show',
        'project.cluster-application.environment-variables',
        'project.cluster-application.resource-limits',
        'project.cluster-application.deployments',
        'project.cluster-application.logs',
        'project.cluster-application.danger',
    ] as $sidebarRoute) {
        $response->assertSee('href="'.route($sidebarRoute, $routeParameters).'"', false);
    }
    foreach ($expectedText as $text) {
        $response->assertSee($text, false);
    }

    expect($response->getContent())->toMatch(
        '/aria-current="page"[^>]*href="'.preg_quote(route($routeName, $routeParameters), '/').'"/'
    )->and(substr_count($response->getContent(), 'aria-current="page"'))->toBe(1)
        ->and(ClusterApplicationShow::SECTIONS)->toContain($section);
})->with([
    'general' => ['project.cluster-application.show', 'general', ['Overview', 'Internal hostname', 'Copy internal hostname', 'Runtime', 'Start command']],
    'environment variables' => ['project.cluster-application.environment-variables', 'environment-variables', ['Environment variables', 'Developer view']],
    'resource limits' => ['project.cluster-application.resource-limits', 'resource-limits', ['CPU limit (cores)', 'Memory reservation (MiB)']],
    'deployments' => ['project.cluster-application.deployments', 'deployments', ['Deployment history', 'Status', 'Revision', 'Duration', 'Node', 'Failed', 'Manual']],
    'logs' => ['project.cluster-application.logs', 'logs', ['Runtime Logs', 'Find in logs']],
    'danger' => ['project.cluster-application.danger', 'danger', ['Danger zone', 'Delete application']],
]);

it('only renders the form that belongs to the current section', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ];

    $this->get(route('project.cluster-application.show', $routeParameters))
        ->assertOk()
        ->assertSee('Start command')
        ->assertSee('wire:poll.10000ms="refresh"', false)
        ->assertDontSee('CPU limit (cores)')
        ->assertDontSee('Deployment history');

    $this->get(route('project.cluster-application.resource-limits', $routeParameters))
        ->assertOk()
        ->assertDontSee('Start command')
        ->assertDontSee('wire:poll.10000ms="refresh"', false);
});

it('lists only deployments in the deployment history with links to their logs', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['operation']->update(['status' => NodeOperationStatus::SUCCEEDED, 'completed_at' => now()]);
    $restart = NodeOperation::factory()->create([
        'node_id' => $deployment['operation']->node_id,
        'node_workload_id' => $deployment['workload']->id,
        'command_type' => 'workload.lifecycle.v1',
        'request' => ['action' => 'restart'],
    ]);
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ];

    $this->get(route('project.cluster-application.deployments', $routeParameters))
        ->assertOk()
        ->assertSee('Success')
        ->assertSee(substr($deployment['operation']->revision->uuid, 0, 7))
        ->assertSee('href="'.route('project.cluster-application.deployment.show', [...$routeParameters, 'deployment_uuid' => $deployment['operation']->uuid]).'"', false)
        ->assertDontSee(route('project.cluster-application.deployment.show', [...$routeParameters, 'deployment_uuid' => $restart->uuid]), false);
});

it('paginates the deployment history', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    NodeOperation::factory()->count(ClusterApplicationShow::DEPLOYMENTS_PER_PAGE)->create([
        'node_id' => $deployment['operation']->node_id,
        'node_workload_id' => $deployment['workload']->id,
        'node_workload_revision_id' => $deployment['operation']->node_workload_revision_id,
        'command_type' => 'workload.deploy.v1',
    ]);
    $total = ClusterApplicationShow::DEPLOYMENTS_PER_PAGE + 1;

    $this->get(route('project.cluster-application.deployments', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ]))
        ->assertOk()
        ->assertSee('1–'.ClusterApplicationShow::DEPLOYMENTS_PER_PAGE.' of '.$total)
        ->assertSee('wire:click="nextPage"', false);
});

it('shows deployment logs built from the recorded operation', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $this->user->update(['name' => '']);
    $operation = $deployment['operation'];
    $operation->update([
        'status' => NodeOperationStatus::FAILED,
        'dispatched_at' => now()->subSeconds(20),
        'started_at' => now()->subSeconds(19),
        'completed_at' => now()->subSeconds(5),
        'error' => "Image pull failed\nmanifest unknown",
    ]);
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ];

    $response = $this->get(route('project.cluster-application.deployment.show', [...$routeParameters, 'deployment_uuid' => $operation->uuid]))
        ->assertOk()
        ->assertSee('Deployment history')
        ->assertSee('data-table-row-active', false)
        ->assertSee('Find in logs')
        ->assertSee('Deployment queued by '.$this->user->email.'.')
        ->assertSee('uses image docker.io/library/nginx:latest.')
        ->assertSee('Command sent to Sentinel on')
        ->assertSee('Sentinel started the deployment.')
        ->assertSee('Deployment failed.')
        ->assertSee('Image pull failed')
        ->assertSee('manifest unknown')
        ->assertDontSee('wire:poll.2000ms', false);

    expect($response->getContent())
        ->toMatch('/aria-current="page"[^>]*href="'.preg_quote(route('project.cluster-application.deployments', $routeParameters), '/').'"/');
});

it('polls quickly while a deployment is in progress', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['operation']->update(['status' => NodeOperationStatus::RUNNING]);

    $this->get(route('project.cluster-application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
        'deployment_uuid' => $deployment['operation']->uuid,
    ]))
        ->assertOk()
        ->assertSee('In progress')
        ->assertSee('wire:poll.2000ms="refresh"', false);
});

it('does not open logs for operations that are not deployments of this application', function () {
    $first = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $second = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'redis:latest', $this->user,
    );
    $restart = NodeOperation::factory()->create([
        'node_id' => $first['operation']->node_id,
        'node_workload_id' => $first['workload']->id,
        'command_type' => 'workload.lifecycle.v1',
    ]);
    $routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $first['workload']->uuid,
    ];

    $this->get(route('project.cluster-application.deployment.show', [...$routeParameters, 'deployment_uuid' => $second['operation']->uuid]))
        ->assertNotFound();
    $this->get(route('project.cluster-application.deployment.show', [...$routeParameters, 'deployment_uuid' => $restart->uuid]))
        ->assertNotFound();
});

it('uses the shared environment variable editor on the environment variables page', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['workload']->environment_variables()->create(['key' => 'APP_ENV', 'value' => 'visible-to-admins']);

    $this->get(route('project.cluster-application.environment-variables', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ]))
        ->assertOk()
        ->assertSeeLivewire(EnvironmentVariableAll::class)
        ->assertSee('Developer view');

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $deployment['workload']])
        ->call('loadEnvironmentVariables')
        ->assertSee('APP_ENV')
        ->call('switch')
        ->assertSet('variables', 'APP_ENV=visible-to-admins');
});

it('closes the edit dialog of the updated environment variable row', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $variable = $deployment['workload']->environment_variables()->create(['key' => 'APP_ENV', 'value' => 'production']);

    Livewire::test(EnvironmentVariableShow::class, ['env' => $variable, 'type' => 'cluster-application'])
        ->assertSeeHtml('$event.detail.envId === '.$variable->id.')')
        ->assertDontSeeHtml('@js(');
});

it('hides environment variable values from members on the environment variables page', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $deployment['workload']->environment_variables()->create(['key' => 'SECRET', 'value' => 'member-must-not-see']);
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->get(route('project.cluster-application.environment-variables', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ]))
        ->assertOk()
        ->assertDontSee('member-must-not-see')
        ->assertDontSee('Developer view')
        ->assertDontSee('cluster-application-desktop-actions', false);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $deployment['workload']])
        ->call('loadEnvironmentVariables')
        ->assertSee('SECRET')
        ->assertDontSee('member-must-not-see');
});

it('does not expose any cluster application section to another team', function (string $routeName) {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $outsider = User::factory()->create();
    $outsiderTeam = $outsider->teams()->firstOrFail();
    $outsiderTeam->update(['show_boarding' => false]);
    Cache::flush();
    $this->actingAs($outsider);
    session(['currentTeam' => $outsiderTeam]);

    $this->get(route($routeName, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
    ]))->assertNotFound();
})->with([
    'project.cluster-application.show',
    'project.cluster-application.environment-variables',
    'project.cluster-application.resource-limits',
    'project.cluster-application.deployments',
    'project.cluster-application.logs',
    'project.cluster-application.danger',
]);

it('does not expose deployment logs to another team', function () {
    $deployment = CreateClusterDockerImageWorkload::run(
        $this->project, $this->environment, $this->cluster, 'nginx:latest', $this->user,
    );
    $outsider = User::factory()->create();
    $outsiderTeam = $outsider->teams()->firstOrFail();
    $outsiderTeam->update(['show_boarding' => false]);
    Cache::flush();
    $this->actingAs($outsider);
    session(['currentTeam' => $outsiderTeam]);

    $this->get(route('project.cluster-application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $deployment['workload']->uuid,
        'deployment_uuid' => $deployment['operation']->uuid,
    ]))->assertNotFound();
});
