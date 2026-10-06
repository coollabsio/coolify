<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\BuildNodeClusterIngressRoutes;
use App\Actions\Node\CreateMoveOperation;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\EnsureNodeWorkloadAddress;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\SetNodeIngress;
use App\Actions\Node\UpdateNodeWorkloadDomains;
use App\Enums\NodeOperationStatus;
use App\Jobs\MoveNodeWorkloadJob;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Livewire\NodeCluster\Show as NodeClusterShow;
use App\Livewire\Project\ClusterApplication\Show as ClusterApplicationShow;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ingressNodeCapabilities(bool $ingress = true): array
{
    return array_values(array_filter([
        'network.wireguard.key.ensure.v1',
        'network.wireguard.reconcile.v1',
        'network.wireguard.inspect.v1',
        'network.firewall.reconcile.v1',
        'network.firewall.inspect.v1',
        'discovery.corrosion.inspect.v1',
        'discovery.corrosion.reconcile.v1',
        'network.cluster.leave.v1',
        'workload.deploy.v1',
        'workload.lifecycle.v1',
        'container.list.v1',
        $ingress ? 'ingress.reconcile.v1' : null,
    ]));
}

function connectIngressNode(Node $node, bool $ingress = true): void
{
    $node->update(['is_usable' => true, 'is_reachable' => true]);
    Cache::put($node->cacheKey(), [
        'status' => 'connected',
        'last_heartbeat_at' => now()->toIso8601String(),
        'capabilities' => ingressNodeCapabilities($ingress),
    ]);
}

/**
 * A cluster whose Nodes converged on the current revision. Every Node is reachable and reports
 * the ingress capability.
 *
 * @return array{0: NodeCluster, 1: list<Node>}
 */
function ingressCluster(User $user, int $count): array
{
    $team = $user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $user, 'Ingress '.Str::random(6));
    $nodes = [];
    for ($index = 1; $index <= $count; $index++) {
        $node = Node::factory()->create(array_filter([
            'team_id' => $team->id,
            'name' => "Ingress Worker {$index}",
            'private_key_id' => Node::query()->where('team_id', $team->id)->value('private_key_id'),
        ]));
        AssignNodeToCluster::run($cluster->refresh(), $node);
        $nodes[] = $node;
    }
    $cluster->refresh()->update(['network_status' => 'active', 'last_reconciled_at' => now()]);
    foreach ($nodes as $node) {
        $node->update([
            'wireguard_public_key' => 'public-'.$node->uuid,
            'network_applied_revision' => $cluster->desired_revision,
            'network_status' => 'converged',
            'corrosion_status' => 'converged',
        ]);
        connectIngressNode($node);
    }

    return [$cluster->refresh(), array_map(fn (Node $node): Node => $node->refresh(), $nodes)];
}

/** @param array<string, mixed> $attributes */
function routedWorkload(Team $team, array $attributes = []): NodeWorkload
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return NodeWorkload::factory()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'internal_dns_name' => 'app-'.Str::lower(Str::random(6)),
        ...$attributes,
    ]);
}

/** @return Collection<int, array{command: string, server_id: string, data: array<string, mixed>}> */
function fakeIngressFlux(ArrayObject $state): Collection
{
    $requests = collect();
    Http::fake(function (Request $request) use ($state, $requests) {
        $command = Str::afterLast($request->url(), '/');
        if (! str_starts_with($command, 'network.') && ! str_starts_with($command, 'discovery.') && $command !== 'ingress.reconcile') {
            // Workload commands fall through to the fake of the test.
            return null;
        }
        $data = $request->data();
        $server = $data['server_id'];
        $requests->push(['command' => $command, 'server_id' => $server, 'data' => $data]);

        return Http::response(['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000, ...match ($command) {
            'network.wireguard.reconcile' => [
                'rollback_cancelled' => true,
                'public_key' => 'public-'.$server,
                'listen_port' => $data['listen_port'],
                'applied_revision' => $data['revision'],
                'drifted' => false,
                'peers' => $data['peers'],
            ],
            'network.firewall.reconcile' => [
                'rollback_cancelled' => true,
                'applied_revision' => $data['revision'],
                'configuration_hash' => 'firewall-'.$server,
                'drifted' => false,
                'table' => 'coolify_cluster',
                'ingress_enforced' => true,
            ],
            'discovery.corrosion.reconcile' => ['version' => 'v1.0.0', 'member_state' => 'joining', 'endpoint_count' => 0],
            'discovery.corrosion.inspect' => ['version' => 'v1.0.0', 'member_state' => 'converged', 'endpoint_count' => 0, 'alive_member_count' => $state['alive']],
            'ingress.reconcile' => [
                'enabled' => $data['enabled'],
                'caddy_version' => $data['caddy_version'],
                'active' => $data['enabled'],
                'revision' => $data['revision'],
                'route_count' => in_array($server, $state['bad_ingress'], true) ? count($data['routes']) + 1 : count($data['routes']),
                'name_count' => in_array($server, $state['bad_names'], true) ? count($data['names']) + 1 : count($data['names']),
            ],
        }]);
    });

    return $requests;
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux.internal');
    config()->set('constants.flux.internal_token', 'internal-token');
    config()->set('constants.flux.public_url', 'http://flux:7443');
    Sleep::fake();
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->team->update(['show_boarding' => false]);
    Cache::flush();
    $this->flux = new ArrayObject(['alive' => 0, 'bad_ingress' => [], 'bad_names' => []]);
    $this->requests = fakeIngressFlux($this->flux);
});

describe('domain validation', function () {
    beforeEach(function () {
        Queue::fake();
        [$this->cluster, [$this->node]] = ingressCluster($this->user, 1);
        $this->workload = routedWorkload($this->team);
        $this->node->workloads()->attach($this->workload, ['container_ip' => '100.64.0.10']);
    });

    it('rejects an invalid domain', function (string $domains, string $message) {
        expect(fn () => UpdateNodeWorkloadDomains::run($this->workload, $domains, 80, $this->user))
            ->toThrow(ValidationException::class, $message);
        expect($this->workload->refresh()->domains)->toBeNull();
    })->with([
        'scheme' => ['http://app.example.com', 'without http:// or https://'],
        'IPv4 address' => ['203.0.113.10', 'is an IP address'],
        'IPv6 address' => ['2001:db8::1', 'is an IP address'],
        'wildcard' => ['*.example.com', 'Wildcard domains are not supported'],
        'path' => ['app.example.com/api', 'without a path'],
        'port' => ['app.example.com:8080', 'without a port'],
        'single label' => ['localhost', 'not a fully qualified domain'],
        'invalid characters' => ['app_name.example.com', 'invalid characters'],
        'label with hyphen at the end' => ['app-.example.com', 'Labels cannot start or end with a hyphen'],
        'duplicate within the application' => ["app.example.com\nAPP.example.com", 'app.example.com is listed more than once.'],
    ]);

    it('normalizes domains to trimmed lowercase hostnames', function () {
        UpdateNodeWorkloadDomains::run($this->workload, " App.Example.COM ,\n\n api.example.com  shop.example.com", '8080', $this->user);

        expect($this->workload->refresh()->domains)->toBe(['app.example.com', 'api.example.com', 'shop.example.com'])
            ->and($this->workload->http_port)->toBe(8080);
    });

    it('requires a port in range when domains are set', function (mixed $port) {
        expect(fn () => UpdateNodeWorkloadDomains::run($this->workload, 'app.example.com', $port, $this->user))
            ->toThrow(ValidationException::class);
        expect($this->workload->refresh()->domains)->toBeNull();
    })->with(['missing' => [''], 'zero' => [0], 'too high' => [65536], 'not a number' => ['http']]);

    it('limits an application to 20 domains', function () {
        $domains = collect(range(1, 21))->map(fn (int $index): string => "app{$index}.example.com")->implode(',');

        expect(fn () => UpdateNodeWorkloadDomains::run($this->workload, $domains, 80, $this->user))
            ->toThrow(ValidationException::class, 'Use at most 20 domains.');
    });

    it('rejects a domain that another application of any team uses', function (bool $sameTeam) {
        $owner = $sameTeam ? $this->team : Team::factory()->create();
        routedWorkload($owner, ['domains' => ['app.example.com'], 'http_port' => 80]);

        expect(fn () => UpdateNodeWorkloadDomains::run($this->workload, 'shop.example.com, APP.example.com', 80, $this->user))
            ->toThrow(ValidationException::class, 'app.example.com is already used by another application.');
        expect($this->workload->refresh()->domains)->toBeNull();
    })->with(['same team' => [true], 'another team' => [false]]);

    it('keeps the domains of the application itself when it saves again', function () {
        UpdateNodeWorkloadDomains::run($this->workload, 'app.example.com', 80, $this->user);
        UpdateNodeWorkloadDomains::run($this->workload, 'app.example.com, www.example.com', 80, $this->user);

        expect($this->workload->refresh()->domains)->toBe(['app.example.com', 'www.example.com']);
    });

    it('removes every domain when the list is empty', function () {
        UpdateNodeWorkloadDomains::run($this->workload, 'app.example.com', 80, $this->user);
        UpdateNodeWorkloadDomains::run($this->workload, '', '', $this->user);

        expect($this->workload->refresh()->domains)->toBeNull()
            ->and($this->workload->http_port)->toBeNull();
    });
});

describe('routes and firewall', function () {
    it('builds one route per domain of each routed workload in the cluster', function () {
        [$cluster, [$first, $second]] = ingressCluster($this->user, 2);
        $shop = routedWorkload($this->team, ['internal_dns_name' => 'shop', 'domains' => ['shop.example.com', 'www.example.com'], 'http_port' => 8080]);
        $first->workloads()->attach($shop, ['container_ip' => '100.64.0.10']);
        $second->workloads()->attach($shop, ['container_ip' => '100.64.1.10']);
        $internal = routedWorkload($this->team, ['internal_dns_name' => 'internal']);
        $first->workloads()->attach($internal, ['container_ip' => '100.64.0.11']);
        [$otherCluster, [$otherNode]] = ingressCluster($this->user, 1);
        $elsewhere = routedWorkload($this->team, ['internal_dns_name' => 'elsewhere', 'domains' => ['elsewhere.example.com'], 'http_port' => 80]);
        $otherNode->workloads()->attach($elsewhere, ['container_ip' => '100.64.9.10']);

        expect(BuildNodeClusterIngressRoutes::run($cluster))->toBe([
            ['host' => 'shop.example.com', 'workload_id' => $shop->uuid, 'namespace' => 'default', 'port' => 8080],
            ['host' => 'www.example.com', 'workload_id' => $shop->uuid, 'namespace' => 'default', 'port' => 8080],
        ])->and(BuildNodeClusterIngressRoutes::run($otherCluster))->toBe([
            ['host' => 'elsewhere.example.com', 'workload_id' => $elsewhere->uuid, 'namespace' => 'default', 'port' => 80],
        ]);
    });

    it('routes a workload by uuid before it has an internal name', function () {
        [$cluster, [$node]] = ingressCluster($this->user, 1);
        $shop = routedWorkload($this->team, ['internal_dns_name' => null, 'domains' => ['shop.example.com'], 'http_port' => 80]);
        $node->workloads()->attach($shop, ['container_ip' => '100.64.0.10']);

        expect(BuildNodeClusterIngressRoutes::run($cluster))->toBe([
            ['host' => 'shop.example.com', 'workload_id' => $shop->uuid, 'namespace' => 'default', 'port' => 80],
        ])->and(BuildNodeClusterIngressRoutes::names($cluster))->toBe([]);
    });

    it('maps the internal names of the cluster workloads to their uuids once each', function () {
        [$cluster, [$first, $second]] = ingressCluster($this->user, 2);
        $shop = routedWorkload($this->team, ['internal_dns_name' => 'shop', 'domains' => ['shop.example.com'], 'http_port' => 80]);
        $first->workloads()->attach($shop, ['container_ip' => '100.64.0.10']);
        $second->workloads()->attach($shop, ['container_ip' => '100.64.1.10']);
        $worker = routedWorkload($this->team, ['internal_dns_name' => 'worker']);
        $first->workloads()->attach($worker, ['container_ip' => '100.64.0.11']);
        $unnamed = routedWorkload($this->team, ['internal_dns_name' => null]);
        $first->workloads()->attach($unnamed, ['container_ip' => '100.64.0.12']);
        [, [$otherNode]] = ingressCluster($this->user, 1);
        $elsewhere = routedWorkload($this->team, ['internal_dns_name' => 'elsewhere']);
        $otherNode->workloads()->attach($elsewhere, ['container_ip' => '100.64.9.10']);

        expect(BuildNodeClusterIngressRoutes::names($cluster))->toBe([
            ['name' => 'shop', 'workload_id' => $shop->uuid, 'namespace' => 'default'],
            ['name' => 'worker', 'workload_id' => $worker->uuid, 'namespace' => 'default'],
        ]);
    });

    it('adds a firewall allow for every container that a route reaches', function () {
        [$cluster, [$first, $second]] = ingressCluster($this->user, 2);
        $cluster->increment('desired_revision');
        $this->flux['alive'] = 1;
        $shop = routedWorkload($this->team, ['domains' => ['shop.example.com', 'www.example.com'], 'http_port' => 8080]);
        $first->workloads()->attach($shop, ['container_ip' => '100.64.0.10']);
        $second->workloads()->attach($shop, ['container_ip' => '100.64.1.10']);
        $api = routedWorkload($this->team);
        $first->workloads()->attach($api, ['container_ip' => '100.64.0.11']);

        ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

        $firewall = $this->requests->firstWhere('command', 'network.firewall.reconcile');
        expect($firewall['data']['ingress_rules'])->toEqualCanonicalizing([
            ['destination_ip' => '100.64.0.10', 'protocol' => 'tcp', 'port' => 8080],
            ['destination_ip' => '100.64.1.10', 'protocol' => 'tcp', 'port' => 8080],
        ]);
    });
});

describe('network reconciliation', function () {
    it('sends ingress last with the full routes and names to every Node and enables Caddy only on ingress Nodes', function () {
        [$cluster, [$ingress, $plain]] = ingressCluster($this->user, 2);
        $ingress->update(['is_ingress' => true]);
        $shop = routedWorkload($this->team, ['internal_dns_name' => 'shop', 'domains' => ['shop.example.com'], 'http_port' => 3000]);
        $plain->workloads()->attach($shop, ['container_ip' => '100.64.1.10']);
        $cluster->increment('desired_revision');
        $this->flux['alive'] = 1;

        ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

        $routes = [['host' => 'shop.example.com', 'workload_id' => $shop->uuid, 'namespace' => 'default', 'port' => 3000]];
        $names = [['name' => 'shop', 'workload_id' => $shop->uuid, 'namespace' => 'default']];
        $ingressRequests = $this->requests->where('command', 'ingress.reconcile')->keyBy('server_id');
        expect($ingressRequests)->toHaveCount(2);
        expect(collect($ingressRequests->get($ingress->uuid)['data'])->except(['server_id', 'command_id'])->all())->toBe([
            'enabled' => true,
            'caddy_version' => ReconcileNodeClusterNetwork::CADDY_VERSION,
            'revision' => $cluster->refresh()->desired_revision,
            'routes' => $routes,
            'names' => $names,
        ]);
        expect(collect($ingressRequests->get($plain->uuid)['data'])->except(['server_id', 'command_id'])->all())->toBe([
            'enabled' => false,
            'caddy_version' => ReconcileNodeClusterNetwork::CADDY_VERSION,
            'revision' => $cluster->desired_revision,
            'routes' => $routes,
            'names' => $names,
        ]);
        foreach ([$ingress, $plain] as $node) {
            expect($this->requests->where('server_id', $node->uuid)->last()['command'])->toBe('ingress.reconcile');
        }
        expect($ingress->refresh()->network_status)->toBe('converged')
            ->and($cluster->nodeIngressState($ingress))->toBe('active')
            ->and($ingress->metadata['ingress_route_count'])->toBe(1)
            ->and($ingress->metadata['ingress_name_count'])->toBe(1)
            ->and($plain->refresh()->metadata['ingress_name_count'])->toBe(1)
            ->and($cluster->nodeIngressState($plain))->toBe('off')
            ->and($plain->network_status)->toBe('converged')
            ->and($cluster->network_status)->toBe('active');
    });

    it('does not apply the network on a Node whose Sentinel cannot apply routes and names', function () {
        [$cluster, [$current, $older]] = ingressCluster($this->user, 2);
        connectIngressNode($older, ingress: false);
        $cluster->increment('desired_revision');

        ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

        expect($older->refresh()->network_status)->toBe('error')
            ->and($this->requests->where('server_id', $older->uuid))->toBeEmpty()
            ->and($this->requests->where('server_id', $current->uuid)->last()['command'])->toBe('ingress.reconcile');
    });

    it('fails the Node whose Sentinel reports a different internal name count', function () {
        [$cluster, [$broken, $healthy]] = ingressCluster($this->user, 2);
        $shop = routedWorkload($this->team, ['internal_dns_name' => 'shop']);
        $healthy->workloads()->attach($shop, ['container_ip' => '100.64.1.10']);
        $cluster->increment('desired_revision');
        $this->flux['alive'] = 1;
        $this->flux['bad_names'] = [$broken->uuid];

        ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

        expect($broken->refresh()->network_status)->toBe('error')
            ->and($broken->network_error)->toContain('unsafe or incomplete')
            ->and($broken->hasAppliedNetworkRevision($cluster->refresh()->desired_revision))->toBeFalse()
            ->and($healthy->refresh()->network_status)->toBe('converged')
            ->and($healthy->hasAppliedNetworkRevision($cluster->desired_revision))->toBeTrue()
            ->and($broken->operations()->where('command_type', 'ingress.reconcile.v1')->sole()->status)->toBe(NodeOperationStatus::FAILED);
    });

    it('marks only the Node with an invalid ingress result as failed', function () {
        [$cluster, [$broken, $healthy]] = ingressCluster($this->user, 2);
        $broken->update(['is_ingress' => true]);
        $healthy->update(['is_ingress' => true]);
        $shop = routedWorkload($this->team, ['domains' => ['shop.example.com'], 'http_port' => 80]);
        $healthy->workloads()->attach($shop, ['container_ip' => '100.64.1.10']);
        $cluster->increment('desired_revision');
        $this->flux['alive'] = 1;
        $this->flux['bad_ingress'] = [$broken->uuid];

        ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

        expect($broken->refresh()->network_status)->toBe('error')
            ->and($broken->network_error)->toContain('unsafe or incomplete')
            ->and($healthy->refresh()->network_status)->toBe('converged')
            ->and($cluster->refresh()->network_status)->toBe('degraded')
            ->and($broken->operations()->where('command_type', 'ingress.reconcile.v1')->sole()->status)->toBe(NodeOperationStatus::FAILED);
    });
});

describe('triggers', function () {
    beforeEach(function () {
        Queue::fake();
        [$this->cluster, [$this->first, $this->second, $this->offline]] = ingressCluster($this->user, 3);
        Cache::forget($this->offline->cacheKey());
        $this->workload = routedWorkload($this->team);
        $this->first->workloads()->attach($this->workload, ['container_ip' => '100.64.0.10']);
        $this->revision = $this->cluster->desired_revision;
    });

    it('bumps the revision and queues reachable Nodes when domains are saved', function () {
        UpdateNodeWorkloadDomains::run($this->workload, 'app.example.com', 80, $this->user);

        expect($this->cluster->refresh()->desired_revision)->toBe($this->revision + 1);
        Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn ($job) => $job->clusterId === $this->cluster->id
            && $job->nodeIds === [$this->first->id, $this->second->id]
            && $job->userId === $this->user->id);
    });

    it('does not queue a run when nothing that routes traffic changed', function () {
        UpdateNodeWorkloadDomains::run($this->workload, '', '8080', $this->user);
        UpdateNodeWorkloadDomains::run($this->workload->refresh(), 'app.example.com', 80, $this->user);
        Queue::fake();
        UpdateNodeWorkloadDomains::run($this->workload->refresh(), 'APP.example.com', '80', $this->user);

        Queue::assertNotPushed(ReconcileNodeClusterNetworkJob::class);
        expect($this->cluster->refresh()->desired_revision)->toBe($this->revision + 1);
    });

    it('bumps the revision and queues reachable Nodes when ingress is toggled', function () {
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        Livewire::test(NodeClusterShow::class, ['cluster_uuid' => $this->cluster->uuid])
            ->call('setIngress', $this->second->uuid, true)
            ->assertDispatched('success');

        expect($this->second->refresh()->is_ingress)->toBeTrue()
            ->and($this->cluster->refresh()->desired_revision)->toBe($this->revision + 1);
        Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn ($job) => $job->nodeIds === [$this->first->id, $this->second->id]);

        Livewire::test(NodeClusterShow::class, ['cluster_uuid' => $this->cluster->uuid])
            ->call('setIngress', $this->second->uuid, false);

        expect($this->second->refresh()->is_ingress)->toBeFalse()
            ->and($this->cluster->refresh()->desired_revision)->toBe($this->revision + 2);
    });

    it('bumps the revision and queues reachable Nodes when a routed workload is deleted', function () {
        $this->workload->update(['domains' => ['app.example.com'], 'http_port' => 80]);

        $this->workload->delete();

        expect($this->cluster->refresh()->desired_revision)->toBe($this->revision + 1);
        Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn ($job) => $job->clusterId === $this->cluster->id);
    });

    it('bumps the revision and queues reachable Nodes when a workload with an internal name is deleted', function () {
        $this->workload->delete();

        expect($this->cluster->refresh()->desired_revision)->toBe($this->revision + 1);
        Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn ($job) => $job->clusterId === $this->cluster->id);
    });

    it('does not queue a run when a workload without domains or an internal name is deleted', function () {
        $this->workload->update(['internal_dns_name' => null]);
        $this->workload->delete();

        expect($this->cluster->refresh()->desired_revision)->toBe($this->revision);
        Queue::assertNotPushed(ReconcileNodeClusterNetworkJob::class);
    });
});

/**
 * Answers the workload commands of a move and records them, in order, with the network commands.
 *
 * @param  Collection<int, array<string, mixed>>  $requests
 */
function fakeMoveWorkloadFlux(NodeWorkload $workload, NodeWorkloadRevision $revision, Node $target, Collection $requests): void
{
    Http::fake(function (Request $request) use ($workload, $revision, $target, $requests) {
        $command = Str::afterLast($request->url(), '/');
        if ($command === 'workload.deploy') {
            $requests->push(['command' => $command, 'server_id' => $request['server_id'], 'data' => $request->data()]);

            return Http::response([
                'command_id' => $request['command_id'],
                'observed_at_unix_ms' => 1_700_000_000_000,
                'runtime_id' => 'target-runtime',
                'name' => 'coolify-'.$workload->uuid.'-main',
                'image' => $revision->image,
            ]);
        }
        if ($command === 'workload.lifecycle') {
            $requests->push(['command' => $command, 'server_id' => $request['server_id'], 'data' => $request->data()]);

            return Http::response([
                'command_id' => $request['command_id'],
                'observed_at_unix_ms' => 1_700_000_002_000,
                'name' => 'coolify-'.$workload->uuid.'-main',
                'action' => 'remove',
            ]);
        }

        return Http::response([
            'command_id' => 'inventory-'.$request['server_id'],
            'observed_at_unix_ms' => 1_700_000_001_000,
            'containers' => $request['server_id'] === $target->uuid ? [[
                'runtime_id' => 'target-runtime',
                'name' => 'coolify-'.$workload->uuid.'-main',
                'image' => $revision->image,
                'state' => 'running',
                'health_status' => 'healthy',
                'restart_count' => 0,
                'ports' => [],
                'labels' => [
                    'coolify.managed' => 'true',
                    'coolify.instance' => 'instance-test',
                    'coolify.workload' => $workload->uuid,
                    'coolify.revision' => $revision->uuid,
                    'coolify.component' => 'main',
                ],
            ]] : [],
        ]);
    });
}

describe('make-before-break move', function () {
    beforeEach(function () {
        [$this->cluster, [$this->source, $this->target]] = ingressCluster($this->user, 2);
        $this->source->update(['is_ingress' => true]);
        $this->target->update(['is_ingress' => true, 'metadata' => healthyIngressNodeMetadata()]);
        $this->flux['alive'] = 1;
        $this->workload = routedWorkload($this->team, ['name' => 'Move App', 'domains' => ['app.example.com'], 'http_port' => 80]);
        $this->source->workloads()->attach($this->workload);
        $this->sourceIp = EnsureNodeWorkloadAddress::run($this->source, $this->workload);
        $this->revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);
        $this->baseRevision = $this->cluster->refresh()->desired_revision;
        fakeMoveWorkloadFlux($this->workload, $this->revision, $this->target, $this->requests);
        Sleep::whenFakingSleep(fn ($duration) => $this->requests->push(['command' => 'sleep', 'seconds' => (int) $duration->totalSeconds]));
    });

    it('allows the target on every Node before it deploys and lets ingress settle before it removes the source', function () {
        $operation = CreateMoveOperation::run($this->source, $this->target, $this->revision, $this->user);
        (new MoveNodeWorkloadJob($operation->id))->handle();

        $targetIp = $this->target->workloads()->whereKey($this->workload->id)->first()->pivot->container_ip;
        $commands = $this->requests->pluck('command')->values();
        $deploy = $commands->search('workload.deploy');
        $remove = $commands->search('workload.lifecycle');
        $allowed = $this->requests->filter(fn (array $request): bool => $request['command'] === 'network.firewall.reconcile'
            && $request['data']['revision'] === $this->baseRevision + 1);
        $ingressApplied = $this->requests->filter(fn (array $request): bool => $request['command'] === 'ingress.reconcile'
            && $request['data']['revision'] === $this->baseRevision + 1);

        expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
            ->and($targetIp)->not->toBeNull()
            ->and($allowed->pluck('server_id')->all())->toBe([$this->source->uuid, $this->target->uuid])
            ->and($allowed->every(fn (array $request): bool => in_array(
                ['destination_ip' => $targetIp, 'protocol' => 'tcp', 'port' => 80],
                $request['data']['ingress_rules'],
                true,
            )))->toBeTrue()
            ->and($ingressApplied)->toHaveCount(2)
            // Every Node applied the revision with the target address before the deployment.
            ->and($ingressApplied->keys()->max())->toBeLessThan($deploy)
            ->and($this->requests->get($deploy)['data']['container_ip'])->toBe($targetIp)
            // The source keeps serving for the settle period after the target became ready.
            ->and($commands->slice($deploy, $remove - $deploy)->contains('sleep'))->toBeTrue()
            ->and($this->requests->slice($deploy, $remove - $deploy)->firstWhere('command', 'sleep')['seconds'])->toBe(MoveNodeWorkloadJob::INGRESS_SETTLE_SECONDS)
            ->and($this->requests->get($remove)['server_id'])->toBe($this->source->uuid)
            ->and($this->source->workloads()->whereKey($this->workload->id)->exists())->toBeFalse();

        // One revision allows the target, one drops the source; the deployment adds none.
        expect($this->cluster->refresh()->desired_revision)->toBe($this->baseRevision + 2);
        $after = $this->requests->filter(fn (array $request): bool => $request['command'] === 'network.firewall.reconcile'
            && $request['data']['revision'] === $this->baseRevision + 2);
        expect($after)->toHaveCount(2)
            ->and($after->keys()->min())->toBeGreaterThan($remove)
            ->and(collect($after->first()['data']['ingress_rules'])->pluck('destination_ip')->all())->toBe([$targetIp]);
    });

    it('fails without touching the source when the network does not apply the target in time', function () {
        Queue::fake();
        $operation = CreateMoveOperation::run($this->source, $this->target, $this->revision, $this->user);
        (new MoveNodeWorkloadJob($operation->id))->handle();

        expect($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
            ->and($operation->error)->toContain('did not allow the target within 120 seconds')
            ->and($operation->error)->toContain('The source remains active.')
            ->and($this->requests->pluck('command')->intersect(['workload.deploy', 'workload.lifecycle']))->toBeEmpty()
            ->and($this->source->workloads()->whereKey($this->workload->id)->exists())->toBeTrue()
            ->and($this->target->workloads()->whereKey($this->workload->id)->exists())->toBeFalse()
            // A second revision withdraws the allow for the detached target address.
            ->and($this->cluster->refresh()->desired_revision)->toBe($this->baseRevision + 2);
        Sleep::assertSleptTimes(MoveNodeWorkloadJob::NETWORK_WAIT_SECONDS);
        Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 2);
    });

    it('fails at once when a Node cannot apply the network for the target', function () {
        $this->flux['bad_ingress'] = [$this->target->uuid];
        $operation = CreateMoveOperation::run($this->source, $this->target, $this->revision, $this->user);
        (new MoveNodeWorkloadJob($operation->id))->handle();

        expect($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
            ->and($operation->error)->toContain("Server {$this->target->name} could not apply the network for the target")
            ->and($this->requests->pluck('command'))->not->toContain('workload.deploy')
            ->and($this->source->workloads()->whereKey($this->workload->id)->exists())->toBeTrue()
            ->and($this->target->workloads()->whereKey($this->workload->id)->exists())->toBeFalse();
        Sleep::assertNeverSlept();
    });

    it('moves a workload without ingress routes without waiting', function () {
        $this->workload->update(['domains' => null, 'http_port' => null]);
        $operation = CreateMoveOperation::run($this->source, $this->target, $this->revision, $this->user);
        (new MoveNodeWorkloadJob($operation->id))->handle();

        expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
            ->and($this->requests->pluck('command')->all())->toBe(['workload.deploy', 'workload.lifecycle'])
            ->and($this->cluster->refresh()->desired_revision)->toBe($this->baseRevision);
        Sleep::assertNeverSlept();
    });
});

function healthyIngressNodeMetadata(): array
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

describe('ingress toggle', function () {
    beforeEach(function () {
        Queue::fake();
        [$this->cluster, [$this->node]] = ingressCluster($this->user, 1);
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);
    });

    it('requires the ingress capability before it turns ingress on', function () {
        connectIngressNode($this->node, ingress: false);
        $revision = $this->cluster->desired_revision;

        Livewire::test(NodeClusterShow::class, ['cluster_uuid' => $this->cluster->uuid])
            ->call('setIngress', $this->node->uuid, true)
            ->assertDispatched('error', 'Upgrade Sentinel on this server to use ingress.');

        expect($this->node->refresh()->is_ingress)->toBeFalse()
            ->and($this->cluster->refresh()->desired_revision)->toBe($revision);
        expect(fn () => SetNodeIngress::run($this->cluster, $this->node, true, $this->user))
            ->toThrow(DomainException::class, 'Upgrade Sentinel on this server to use ingress.');
        Queue::assertNotPushed(ReconcileNodeClusterNetworkJob::class);
    });

    it('shows the ingress state of each Node', function () {
        $this->node->update(['is_ingress' => true]);

        $this->get(route('node-cluster.nodes', $this->cluster->uuid))
            ->assertSuccessful()
            ->assertSee('Ingress')
            ->assertSee('Pending')
            ->assertSee('Turn off ingress');

        $this->node->update(['metadata' => ['ingress_active' => true, 'ingress_applied_revision' => $this->cluster->desired_revision]]);

        $this->get(route('node-cluster.nodes', $this->cluster->uuid))
            ->assertSee('Active')
            ->assertSee($this->node->ip);
    });

    it('prevents members from toggling ingress', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'member']);
        $this->actingAs($member);

        Livewire::test(NodeClusterShow::class, ['cluster_uuid' => $this->cluster->uuid])
            ->call('setIngress', $this->node->uuid, true)
            ->assertForbidden();

        expect($this->node->refresh()->is_ingress)->toBeFalse()
            ->and(fn () => SetNodeIngress::run($this->cluster, $this->node, true, $member))->toThrow(AuthorizationException::class);
    });

    it('prevents another team from toggling ingress', function () {
        $stranger = User::factory()->create();
        $strangerTeam = $stranger->teams()->firstOrFail();
        $this->actingAs($stranger);
        session(['currentTeam' => $strangerTeam]);

        $this->get(route('node-cluster.nodes', $this->cluster->uuid))->assertNotFound();

        expect(fn () => SetNodeIngress::run($this->cluster, $this->node, true, $stranger))->toThrow(AuthorizationException::class)
            ->and($this->node->refresh()->is_ingress)->toBeFalse();
    });

    it('turns ingress off when the Node leaves the cluster', function () {
        $this->node->update(['is_ingress' => true]);
        Cache::forget($this->node->cacheKey());

        RemoveNodeFromCluster::run($this->cluster, $this->node, $this->user);

        expect($this->node->refresh()->is_ingress)->toBeFalse();
    });
});

describe('cluster application domains page', function () {
    beforeEach(function () {
        Queue::fake();
        [$this->cluster, [$this->node, $this->edge]] = ingressCluster($this->user, 2);
        $this->workload = routedWorkload($this->team);
        $this->node->workloads()->attach($this->workload, ['container_ip' => '100.64.0.10']);
        NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);
        $this->parameters = [
            'project_uuid' => $this->workload->project->uuid,
            'environment_uuid' => $this->workload->environment->uuid,
            'workload_uuid' => $this->workload->uuid,
        ];
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);
    });

    it('saves domains and shows the URLs and ingress addresses', function () {
        $this->edge->update(['is_ingress' => true]);

        Livewire::test(ClusterApplicationShow::class, $this->parameters)
            ->assertSee('Domains')
            ->assertSee('HTTPS is not available yet.')
            ->set('domains', "App.Example.com\nwww.example.com")
            ->set('httpPort', '3000')
            ->call('saveDomains')
            ->assertHasNoErrors()
            ->assertDispatched('success')
            ->assertSet('domains', "app.example.com\nwww.example.com")
            ->assertSee('http://app.example.com')
            ->assertSee('http://www.example.com')
            ->assertSee('Point an A record for each domain to one or more of these addresses.')
            ->assertSee($this->edge->ip)
            ->assertDontSee('No ingress server');

        expect($this->workload->refresh()->domains)->toBe(['app.example.com', 'www.example.com'])
            ->and($this->workload->http_port)->toBe(3000);
        Queue::assertPushed(ReconcileNodeClusterNetworkJob::class);
    });

    it('shows validation errors on the domain fields', function () {
        Livewire::test(ClusterApplicationShow::class, $this->parameters)
            ->set('domains', '*.example.com')
            ->set('httpPort', '80')
            ->call('saveDomains')
            ->assertHasErrors(['domains'])
            ->set('domains', 'app.example.com')
            ->set('httpPort', '')
            ->call('saveDomains')
            ->assertHasErrors(['httpPort']);

        expect($this->workload->refresh()->domains)->toBeNull();
    });

    it('warns when the cluster has no ingress Node', function () {
        Livewire::test(ClusterApplicationShow::class, $this->parameters)
            ->assertSee('No ingress server')
            ->assertSee(route('node-cluster.nodes', ['cluster_uuid' => $this->cluster->uuid]), false);
    });

    it('forbids members from saving domains', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'member']);
        $this->actingAs($member);

        Livewire::test(ClusterApplicationShow::class, $this->parameters)
            ->set('domains', 'app.example.com')
            ->set('httpPort', '80')
            ->call('saveDomains')
            ->assertForbidden();

        expect($this->workload->refresh()->domains)->toBeNull()
            ->and(fn () => UpdateNodeWorkloadDomains::run($this->workload, 'app.example.com', 80, $member))->toThrow(AuthorizationException::class);
    });

    it('does not let another team open or change the application domains', function () {
        $stranger = User::factory()->create();
        $strangerTeam = $stranger->teams()->firstOrFail();
        $this->actingAs($stranger);
        session(['currentTeam' => $strangerTeam]);

        $this->get(route('project.cluster-application.show', $this->parameters))->assertNotFound();

        expect(fn () => UpdateNodeWorkloadDomains::run($this->workload, 'app.example.com', 80, $stranger))->toThrow(AuthorizationException::class)
            ->and($this->workload->refresh()->domains)->toBeNull();
        Queue::assertNotPushed(ReconcileNodeClusterNetworkJob::class);
    });
});
