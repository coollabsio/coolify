<?php

use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Sentinel\FetchContainerLogs;
use App\Enums\NodeContainerManagementState;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use App\Jobs\DeleteNodeWorkloadJob;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Livewire\Project\ClusterApplication\Show as ClusterApplicationShow;
use App\Livewire\Project\New\DockerImage;
use App\Livewire\Project\Shared\Danger;
use App\Livewire\Project\Shared\GetLogs;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeFirewallRule;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');
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
    $makeNode = fn (string $name): Node => Node::factory()->create([
        'team_id' => $this->team->id,
        'node_cluster_id' => $this->cluster->id,
        'private_key_id' => $key->id,
        'name' => $name,
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => [
            'cpu_usage_percent' => 10,
            'memory_bytes' => 1_000,
            'memory_used_bytes' => 100,
            'disk_total_bytes' => 1_000,
            'disk_available_bytes' => 900,
            'collected_at' => now()->toIso8601String(),
            'container_inventory_observed_at' => now()->toIso8601String(),
        ],
    ]);
    $this->nodeA = $makeNode('server-a');
    $this->nodeB = $makeNode('server-b');
    $this->workload = NodeWorkload::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'environment_id' => $this->environment->id,
        'name' => 'hello',
        'internal_dns_name' => 'hello',
    ]);
    $this->revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/nginxdemos/hello:latest',
    ]);
    $this->workload->nodes()->attach([$this->nodeA->id, $this->nodeB->id]);
    $this->routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'workload_uuid' => $this->workload->uuid,
    ];
    $this->placeContainer = fn (Node $node, string $state): NodeContainer => NodeContainer::factory()->create([
        'node_id' => $node->id,
        'node_workload_id' => $this->workload->id,
        'node_workload_revision_id' => $this->revision->id,
        'name' => 'coolify-'.$this->workload->uuid.'-main',
        'image' => $this->revision->image,
        'state' => $state,
        'management_state' => NodeContainerManagementState::MANAGED,
        'is_managed' => true,
    ]);
    $this->actingAsMember = function (): User {
        $member = User::factory()->create();
        $member->teams()->attach($this->team, ['role' => 'member']);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);

        return $member;
    };
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('names', function () {
    it('derives a readable default name from the image', function (string $image, string $expected) {
        expect(CreateClusterDockerImageWorkload::defaultName($image))->toBe($expected);
    })->with([
        'docker hub library' => ['docker.io/library/nginx:1.27', 'nginx'],
        'short name' => ['nginx', 'nginx'],
        'docker hub user' => ['nginxdemos/hello:latest', 'hello'],
        'registry digest' => ['ghcr.io/acme/api@sha256:'.str_repeat('a', 64), 'api'],
        'registry port' => ['localhost:5000/team/web-app:v1', 'web-app'],
        'tag and digest' => ['registry.example.com:8443/app:1.0@sha256:'.str_repeat('b', 64), 'app'],
        'too short' => ['docker.io/library/go:1', 'go-app'],
    ]);

    it('adds a numeric suffix when the name is taken in the same environment', function () {
        Queue::fake();
        $names = collect(range(1, 3))->map(fn () => CreateClusterDockerImageWorkload::run(
            $this->project, $this->environment, $this->cluster, 'docker.io/library/nginx:1.27', $this->user,
        )['workload']->name);
        $otherEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
        $otherName = CreateClusterDockerImageWorkload::run(
            $this->project, $otherEnvironment, $this->cluster, 'nginx:latest', $this->user,
        )['workload']->name;

        expect($names->all())->toBe(['nginx', 'nginx-2', 'nginx-3'])
            ->and($otherName)->toBe('nginx');
    });

    it('uses the optional name of the Docker image form', function (string $name, string $expected) {
        Queue::fake();
        Livewire::withUrlParams(['cluster' => $this->cluster->uuid])
            ->test(DockerImage::class, ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid])
            ->set('parameters', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid])
            ->set('imageName', 'ghcr.io/acme/api')
            ->set('imageTag', 'v1')
            ->set('name', $name)
            ->call('submit')
            ->assertHasNoErrors();

        expect(NodeWorkload::query()->latest('id')->firstOrFail()->name)->toBe($expected);
    })->with([
        'custom' => ['Storefront API', 'Storefront API'],
        'empty' => ['', 'api'],
    ]);

    it('validates the name of the Docker image form like v4 application names', function () {
        Livewire::withUrlParams(['cluster' => $this->cluster->uuid])
            ->test(DockerImage::class, ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid])
            ->set('imageName', 'nginx')
            ->set('name', '<script>')
            ->call('submit')
            ->assertHasErrors('name');
    });

    it('renames the application without changing its internal DNS name', function () {
        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->assertSet('name', 'hello')
            ->set('name', 'Customer portal')
            ->set('description', 'Public website')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->assertDispatched('success', 'Application updated.');

        $this->workload->refresh();
        expect($this->workload->name)->toBe('Customer portal')
            ->and($this->workload->description)->toBe('Public website')
            ->and($this->workload->internal_dns_name)->toBe('hello');
    });

    it('validates the application name like v4', function (string $name) {
        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->set('name', $name)
            ->call('saveDetails')
            ->assertHasErrors('name');

        expect($this->workload->refresh()->name)->toBe('hello');
    })->with(['empty' => [''], 'too short' => ['ab'], 'unsafe' => ['<b>x</b>']]);

    it('forbids members from renaming the application', function () {
        ($this->actingAsMember)();

        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->set('name', 'Renamed')
            ->call('saveDetails')
            ->assertForbidden();

        expect($this->workload->refresh()->name)->toBe('hello');
    });

    it('keeps the names of existing applications', function () {
        $this->workload->update(['name' => 'docker-image-legacy']);

        $this->get(route('project.cluster-application.show', $this->routeParameters))
            ->assertOk()
            ->assertSee('docker-image-legacy')
            ->assertSee('Application details')
            ->assertSee('Renaming it does not change its internal hostname.');
    });
});

describe('heading actions', function () {
    it('offers Deploy and Remove container when the application exited', function () {
        Queue::fake();
        ($this->placeContainer)($this->nodeA, 'exited');
        ($this->placeContainer)($this->nodeB, 'exited');

        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->assertSet('status', 'Stopped')
            ->assertSee(['Deploy', 'Remove container', 'Confirm Container Removal?', 'The exited application container will be removed.'])
            ->assertDontSee('Confirm Application Stopping?')
            ->assertDontSeeHtml('cluster-application-restart-trigger\')?.click()')
            ->assertSeeHtml("getElementById('cluster-application-stop-trigger')")
            ->call('removeContainer')
            ->assertDispatched('info', 'Removing the application container.');

        $operations = NodeOperation::query()->where('node_workload_id', $this->workload->id)->orderBy('node_id')->get();
        expect($operations->pluck('node_id')->all())->toBe([$this->nodeA->id, $this->nodeB->id])
            ->and($operations->pluck('command_type')->unique()->all())->toBe(['workload.lifecycle.v1'])
            ->and($operations->map(fn (NodeOperation $operation) => data_get($operation->request, 'action'))->unique()->all())->toBe(['remove'])
            ->and($this->workload->refresh()->desired_state)->toBe(NodeWorkloadDesiredState::REMOVED)
            ->and($this->workload->nodes()->count())->toBe(2);
        Queue::assertPushed(ManageNodeWorkloadJob::class, 2);
    });

    it('does not offer Remove container when no container exists', function () {
        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->assertSet('status', 'Missing')
            ->assertSet('containerPresent', false)
            ->assertSee('Deploy')
            ->assertDontSeeHtml("getElementById('cluster-application-stop-trigger')");
    });

    it('offers Deploy, Restart, and Stop while the application runs', function (string $method, string $action) {
        Queue::fake();
        ($this->placeContainer)($this->nodeA, 'running');
        ($this->placeContainer)($this->nodeB, 'running');

        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->assertSet('status', 'Running')
            ->assertSee(['Deploy', 'Restart', 'Stop', 'Confirm Application Stopping?', 'Confirm Application Restart?', 'This application will be restarted without rebuilding.'])
            ->assertDontSee('Confirm Container Removal?')
            ->call($method);

        $operations = NodeOperation::query()->where('node_workload_id', $this->workload->id)->orderBy('node_id')->get();
        expect($operations)->toHaveCount(2)
            ->and($operations->pluck('node_id')->all())->toBe([$this->nodeA->id, $this->nodeB->id])
            ->and($operations->map(fn (NodeOperation $operation) => data_get($operation->request, 'action'))->unique()->all())->toBe([$action]);
        Queue::assertPushed(ManageNodeWorkloadJob::class, 2);
    })->with([
        'restart' => ['restart', 'restart'],
        'stop' => ['stop', 'stop'],
    ]);

    it('shows the worst state of all servers', function () {
        ($this->placeContainer)($this->nodeA, 'running');
        ($this->placeContainer)($this->nodeB, 'exited');

        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->assertSet('status', 'Stopped')
            ->assertSee('Remove container');
    });

    it('deploys on every server and opens the first deployment', function () {
        Queue::fake();

        $component = Livewire::test(ClusterApplicationShow::class, $this->routeParameters)->call('deploy');

        $operations = NodeOperation::query()->where('node_workload_id', $this->workload->id)->orderBy('id')->get();
        expect($operations)->toHaveCount(2)
            ->and($operations->pluck('command_type')->unique()->all())->toBe(['workload.deploy.v1'])
            ->and($operations->pluck('node_id')->sort()->values()->all())->toBe([$this->nodeA->id, $this->nodeB->id]);
        $component->assertRedirect(route('project.cluster-application.deployment.show', [
            ...$this->routeParameters,
            'deployment_uuid' => $operations->first()->uuid,
        ]));
        Queue::assertPushed(DeployNodeWorkloadJob::class, 2);
    });

    it('links to the running deployment', function () {
        $operation = NodeOperation::factory()->create([
            'node_id' => $this->nodeA->id,
            'node_workload_id' => $this->workload->id,
            'command_type' => 'workload.deploy.v1',
            'status' => NodeOperationStatus::RUNNING,
        ]);

        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->assertSee('View log')
            ->assertSeeHtml(route('project.cluster-application.deployment.show', [...$this->routeParameters, 'deployment_uuid' => $operation->uuid]));
    });

    it('forbids members from running lifecycle actions', function (string $method) {
        Queue::fake();
        ($this->placeContainer)($this->nodeA, 'running');
        ($this->actingAsMember)();

        Livewire::test(ClusterApplicationShow::class, $this->routeParameters)
            ->assertDontSee('cluster-application-desktop-actions')
            ->call($method)
            ->assertDispatched('error');

        expect(NodeOperation::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with(['deploy', 'restart', 'stop', 'removeContainer']);
});

describe('danger zone', function () {
    beforeEach(function () {
        Sleep::fake();
        $this->otherWorkload = NodeWorkload::factory()->create(['team_id' => $this->team->id, 'name' => 'api']);
        $this->firewallRule = NodeFirewallRule::factory()->create([
            'node_cluster_id' => $this->cluster->id,
            'source_workload_id' => $this->otherWorkload->id,
            'destination_workload_id' => $this->workload->id,
        ]);
        EnvironmentVariable::query()->create([
            'key' => 'SECRET',
            'value' => 'value',
            'resourceable_type' => NodeWorkload::class,
            'resourceable_id' => $this->workload->id,
        ]);
        ($this->placeContainer)($this->nodeA, 'running');
        ($this->placeContainer)($this->nodeB, 'running');
        $this->fakeFlux = function (array $failingNodeUuids = []): void {
            Http::fake(function ($request) use ($failingNodeUuids) {
                if (in_array($request['server_id'] ?? null, $failingNodeUuids, true)) {
                    return Http::response('server is not connected', 404);
                }
                if (str_ends_with($request->url(), '/v1/commands/workload.lifecycle')) {
                    return Http::response([
                        'command_id' => $request['command_id'],
                        'observed_at_unix_ms' => 1_700_000_000_000,
                        'name' => $request['name'],
                        'action' => $request['action'],
                    ]);
                }

                return Http::response([
                    'command_id' => 'inventory-'.Str::uuid(),
                    'observed_at_unix_ms' => 1_700_000_000_100,
                    'containers' => [],
                ]);
            });
        };
    });

    it('removes the container on every server and deletes the application', function () {
        Queue::fake();
        Notification::fake();
        ($this->fakeFlux)();
        $this->workload->update(['domains' => ['hello.example.com'], 'http_port' => 80]);
        $revisionBefore = $this->cluster->refresh()->desired_revision;

        (new DeleteNodeWorkloadJob($this->workload->id, $this->user->id))->handle();

        $removals = NodeOperation::query()->where('command_type', 'workload.lifecycle.v1')->get();
        expect(NodeWorkload::query()->whereKey($this->workload->id)->exists())->toBeFalse()
            ->and($removals)->toHaveCount(2)
            ->and($removals->pluck('status')->unique()->all())->toBe([NodeOperationStatus::SUCCEEDED])
            ->and($removals->pluck('node_workload_id')->unique()->all())->toBe([null])
            ->and(NodeWorkloadRevision::query()->where('node_workload_id', $this->workload->id)->exists())->toBeFalse()
            ->and(EnvironmentVariable::query()->where('resourceable_type', NodeWorkload::class)->where('resourceable_id', $this->workload->id)->exists())->toBeFalse()
            ->and(NodeFirewallRule::query()->whereKey($this->firewallRule->id)->exists())->toBeFalse()
            ->and($this->nodeA->workloads()->count() + $this->nodeB->workloads()->count())->toBe(0)
            ->and($this->cluster->refresh()->desired_revision)->toBe($revisionBefore + 1)
            ->and(NodeWorkload::query()->whereKey($this->otherWorkload->id)->exists())->toBeTrue();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/commands/workload.lifecycle')
            && $request['server_id'] === $this->nodeB->uuid && $request['action'] === 'remove');
        Notification::assertNothingSent();
    });

    it('bumps the cluster network revision only when routes, an internal name, or firewall rules referenced the application', function () {
        Queue::fake();
        ($this->fakeFlux)();
        $this->firewallRule->delete();
        $this->workload->update(['internal_dns_name' => null]);
        $revisionBefore = $this->cluster->refresh()->desired_revision;

        (new DeleteNodeWorkloadJob($this->workload->id, $this->user->id))->handle();

        expect(NodeWorkload::query()->whereKey($this->workload->id)->exists())->toBeFalse()
            ->and($this->cluster->refresh()->desired_revision)->toBe($revisionBefore);
    });

    it('bumps the cluster network revision when the deleted application had an internal name', function () {
        Queue::fake();
        ($this->fakeFlux)();
        $this->firewallRule->delete();
        $revisionBefore = $this->cluster->refresh()->desired_revision;

        (new DeleteNodeWorkloadJob($this->workload->id, $this->user->id))->handle();

        expect(NodeWorkload::query()->whereKey($this->workload->id)->exists())->toBeFalse()
            ->and($this->cluster->refresh()->desired_revision)->toBe($revisionBefore + 1);
    });

    it('keeps the application and notifies the team when a server cannot remove the container', function () {
        Queue::fake();
        Notification::fake();
        $this->team->webhookNotificationSettings()->update([
            'webhook_enabled' => true,
            'webhook_url' => 'https://example.com/webhook',
        ]);
        ($this->fakeFlux)([$this->nodeB->uuid]);
        $revisionBefore = $this->cluster->refresh()->desired_revision;

        expect(fn () => (new DeleteNodeWorkloadJob($this->workload->id, $this->user->id))->handle())
            ->toThrow(RuntimeException::class, 'server-b');

        $this->workload->refresh();
        expect($this->workload->nodes()->count())->toBe(2)
            ->and($this->workload->desired_state)->toBe(NodeWorkloadDesiredState::REMOVED)
            ->and(NodeFirewallRule::query()->whereKey($this->firewallRule->id)->exists())->toBeTrue()
            ->and($this->workload->environment_variables()->count())->toBe(1)
            ->and($this->cluster->refresh()->desired_revision)->toBe($revisionBefore)
            ->and(NodeOperation::query()->where('node_id', $this->nodeB->id)->sole()->status)->toBe(NodeOperationStatus::FAILED);
        Notification::assertSentTo($this->team, GeneralNotification::class,
            fn (GeneralNotification $notification) => ! $notification->success
                && str_contains($notification->message, "Application deletion failed for 'hello'")
                && str_contains($notification->message, 'Remove from Coolify only'));
    });

    it('removes the application from Coolify only without contacting Sentinel', function () {
        Queue::fake();
        Http::fake();

        (new DeleteNodeWorkloadJob($this->workload->id, $this->user->id, deleteFromCoolifyOnly: true))->handle();

        expect(NodeWorkload::query()->whereKey($this->workload->id)->exists())->toBeFalse()
            ->and(NodeFirewallRule::query()->whereKey($this->firewallRule->id)->exists())->toBeFalse();
        Http::assertNothingSent();
    });

    it('reuses the v4 danger zone and queues the deletion', function () {
        Queue::fake();
        Cache::put($this->nodeA->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String()]);
        Cache::put($this->nodeB->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String()]);

        Livewire::test(Danger::class, ['resource' => $this->workload])
            ->assertSet('resourceName', 'hello')
            ->assertSet('canDelete', true)
            ->assertSee(['Delete application', 'remove its container from every server it runs on'])
            ->assertDontSee(['Server is not reachable', __('resource.delete_volumes')])
            ->call('delete', 'password')
            ->assertRedirect(route('project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
            ]));

        Queue::assertPushed(DeleteNodeWorkloadJob::class, fn (DeleteNodeWorkloadJob $job) => $job->workloadId === $this->workload->id
            && $job->requestedById === $this->user->id
            && ! $job->deleteFromCoolifyOnly);
    });

    it('requires the password to delete the application', function () {
        Queue::fake();

        Livewire::test(Danger::class, ['resource' => $this->workload])
            ->call('delete', 'wrong-password')
            ->assertHasErrors('password');

        Queue::assertNothingPushed();
    });

    it('warns about unreachable servers and offers removal from Coolify only', function () {
        Queue::fake();
        Cache::put($this->nodeA->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String()]);

        Livewire::test(Danger::class, ['resource' => $this->workload])
            ->assertSee(['Server is not reachable', 'Coolify cannot remove the container on server-b', 'Remove from Coolify only'])
            ->call('deleteFromCoolifyOnly', 'password')
            ->assertRedirect();

        Queue::assertPushed(DeleteNodeWorkloadJob::class, fn (DeleteNodeWorkloadJob $job) => $job->deleteFromCoolifyOnly);
    });

    it('forbids members from deleting the application', function () {
        Queue::fake();
        ($this->actingAsMember)();

        Livewire::test(Danger::class, ['resource' => $this->workload])
            ->assertSet('canDelete', false)
            ->assertSee('Insufficient permissions')
            ->call('delete', 'password')
            ->assertDispatched('error');

        Queue::assertNothingPushed();
    });

    it('renders the danger zone section in the cluster application page', function () {
        $this->get(route('project.cluster-application.danger', $this->routeParameters))
            ->assertOk()
            ->assertSee(['Danger zone', 'Delete application', 'Enter the resource name to confirm permanent deletion']);
    });
});

describe('logs', function () {
    beforeEach(function () {
        $this->logLines = "2026-10-06T08:00:00.123456789Z GET / 200\n2026-10-06T08:00:01.000000000Z GET /health 200\n";
        $this->getLogs = fn (?Node $node = null) => Livewire::test(GetLogs::class, [
            'server' => $node ?? $this->nodeA,
            'resource' => $this->workload,
            'container' => 'coolify-'.$this->workload->uuid.'-main',
            'displayName' => $this->workload->name,
            'expandByDefault' => true,
        ]);
    });

    it('reads container logs from Sentinel through Flux without creating operations', function () {
        Http::fake(['flux:7080/v1/commands/container.logs' => Http::response([
            'command_id' => 'command-1',
            'observed_at_unix_ms' => 1_789_140_000_000,
            'name' => 'coolify-'.$this->workload->uuid.'-main',
            'logs' => $this->logLines,
            'truncated' => false,
        ])]);

        ($this->getLogs)()
            ->call('getLogs', true)
            ->assertSet('logsUnavailableMessage', null)
            ->assertSee(['GET / 200', 'GET /health 200', '2026-Oct-06 08:00:01']);

        Http::assertSent(fn ($request) => $request->url() === 'http://flux:7080/v1/commands/container.logs'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['server_id'] === $this->nodeA->uuid
            && Str::isUuid($request['command_id'])
            && $request['name'] === 'coolify-'.$this->workload->uuid.'-main'
            && $request['lines'] === 100
            && ! array_key_exists('since_unix_seconds', $request->data()));
        expect(NodeOperation::query()->count())->toBe(0);
    });

    it('removes timestamps and caps the line count at the Sentinel limit', function () {
        Http::fake(['flux:7080/*' => Http::response([
            'command_id' => 'command-1',
            'observed_at_unix_ms' => 1_789_140_000_000,
            'name' => 'coolify-'.$this->workload->uuid.'-main',
            'logs' => $this->logLines,
            'truncated' => true,
        ])]);

        $component = ($this->getLogs)()
            ->set('showTimeStamps', false)
            ->call('showAllLogs')
            ->assertSet('numberOfLines', FetchContainerLogs::MAX_LINES);

        expect($component->get('outputs'))->toStartWith("GET / 200\nGET /health 200")
            ->toContain('[... Output truncated by Sentinel ...]')
            ->not->toContain('2026-10-06T08');
        Http::assertSent(fn ($request) => $request['lines'] === FetchContainerLogs::MAX_LINES);
    });

    it('asks to upgrade Sentinel when the server lacks the container logs capability', function () {
        Http::fake();
        Cache::put($this->nodeA->cacheKey(), ['status' => 'connected', 'capabilities' => ['logs.read.v1']]);

        ($this->getLogs)()
            ->call('getLogs', true)
            ->assertSet('outputs', '')
            ->assertSet('logsUnavailableMessage', 'Upgrade Sentinel on this server to view logs.')
            ->assertSee('Upgrade Sentinel on this server to view logs.');

        Http::assertNothingSent();
    });

    it('explains Flux errors', function (int $status, mixed $body, string $message) {
        Http::fake(['flux:7080/*' => Http::response($body, $status)]);

        ($this->getLogs)()
            ->call('getLogs', true)
            ->assertSet('outputs', '')
            ->assertSet('logsUnavailableMessage', $message)
            ->assertSee($message);
    })->with([
        'capability not granted' => [409, ['message' => 'capability not granted'], 'Upgrade Sentinel on this server to view logs.'],
        'server not connected' => [404, ['message' => 'not connected'], 'Sentinel on this server is not connected.'],
        'invalid request' => [422, ['message' => 'lines must be at most 10000'], 'Flux rejected the log request: lines must be at most 10000'],
        'sentinel error' => [502, ['message' => 'No such container'], 'Sentinel could not read the logs: No such container'],
        'invalid response' => [200, ['unexpected' => true], 'Flux returned an invalid log response.'],
    ]);

    it('downloads all logs through the same data source', function () {
        Http::fake(['flux:7080/*' => Http::response([
            'command_id' => 'command-1',
            'observed_at_unix_ms' => 1_789_140_000_000,
            'name' => 'coolify-'.$this->workload->uuid.'-main',
            'logs' => $this->logLines,
            'truncated' => false,
        ])]);

        $component = ($this->getLogs)()->call('downloadAllLogs');

        expect($component->effects['returns'][0])->toContain('GET /health 200');
        Http::assertSent(fn ($request) => $request['lines'] === FetchContainerLogs::MAX_LINES);
    });

    it('lets members read logs like v4', function () {
        Http::fake(['flux:7080/*' => Http::response([
            'command_id' => 'command-1',
            'observed_at_unix_ms' => 1_789_140_000_000,
            'name' => 'coolify-'.$this->workload->uuid.'-main',
            'logs' => $this->logLines,
            'truncated' => false,
        ])]);
        ($this->actingAsMember)();

        ($this->getLogs)()->call('getLogs', true)->assertSee('GET /health 200');
    });

    it('does not read logs from a server where the application is not placed', function () {
        Http::fake();
        $this->workload->nodes()->detach($this->nodeB->id);

        ($this->getLogs)($this->nodeB)->call('getLogs', true)->assertSet('outputs', 'Unauthorized.');

        Http::assertNothingSent();
    });

    it('does not read logs of another team', function () {
        Http::fake();
        $outsider = User::factory()->create();
        $outsiderTeam = $outsider->teams()->firstOrFail();
        $component = ($this->getLogs)();
        $this->actingAs($outsider);
        session(['currentTeam' => $outsiderTeam]);

        $component->call('getLogs', true)->assertSet('outputs', 'Unauthorized.');
        expect($component->call('downloadAllLogs')->effects['returns'][0])->toBe('');

        Http::assertNothingSent();
    });

    it('renders one log block per server like v4', function () {
        $this->get(route('project.cluster-application.logs', $this->routeParameters))
            ->assertOk()
            ->assertSee(['Runtime Logs', 'server-a', 'server-b', 'Find in logs'])
            ->assertSeeLivewire(GetLogs::class);
    });

    it('lets members open the logs page', function () {
        ($this->actingAsMember)();

        $this->get(route('project.cluster-application.logs', $this->routeParameters))->assertOk();
    });
});
