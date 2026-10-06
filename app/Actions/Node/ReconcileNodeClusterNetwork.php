<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class ReconcileNodeClusterNetwork
{
    use AsAction;

    public const CORROSION_VERSION = 'v1.0.0';

    /** The Caddy release that Sentinel runs on ingress Nodes. */
    public const CADDY_VERSION = 'v2.11.7';

    /** Applies ingress routes and internal DNS names; every Node writes them to Corrosion. */
    public const INGRESS_CAPABILITY = 'ingress.reconcile.v1';

    public const REQUIRED_CAPABILITIES = [
        'network.wireguard.key.ensure.v1',
        'network.wireguard.reconcile.v1',
        'network.firewall.reconcile.v1',
        'discovery.corrosion.inspect.v1',
        'discovery.corrosion.reconcile.v1',
        self::INGRESS_CAPABILITY,
    ];

    private const CORROSION_INSPECTIONS = 20;

    private const MAX_BACKOFF_MINUTES = 30;

    /**
     * Applies the desired network revision Node by Node. Every reachable Node converges on its
     * own: a failing Node becomes `error` and an offline Node stays `pending` without blocking
     * the others. Offline Nodes with a key stay WireGuard and Corrosion peers.
     *
     * @param  list<int>|null  $nodeIds  Limit the run to these member Nodes. Null runs every member.
     * @param  bool  $onlyDueNodes  Skip Nodes that converged or still wait for their retry backoff.
     * @return list<NodeOperation>
     *
     * @throws LockTimeoutException when another network run of this cluster holds the lock.
     */
    public function handle(NodeCluster $cluster, User $user, ?array $nodeIds = null, bool $onlyDueNodes = false): array
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $lock = $cluster->networkLock();
        if (! $lock->get()) {
            throw new LockTimeoutException('Another network reconciliation of this cluster is running.');
        }

        try {
            return $this->reconcile($cluster->refresh(), $user, $nodeIds, $onlyDueNodes);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a Corrosion inspection shows the Node joined every reachable peer. Sentinel reports
     * `alive_member_count`; older Sentinels only report `member_state`, which needs every
     * configured peer, including offline ones.
     *
     * @param  array<string, mixed>  $result
     */
    public static function corrosionConverged(array $result, int $expectedAlivePeers): bool
    {
        if (data_get($result, 'version') !== self::CORROSION_VERSION) {
            return false;
        }
        $aliveMembers = data_get($result, 'alive_member_count');
        if (is_numeric($aliveMembers)) {
            return in_array(data_get($result, 'member_state'), ['joining', 'converged'], true)
                && (int) $aliveMembers >= $expectedAlivePeers;
        }

        return data_get($result, 'member_state') === 'converged';
    }

    /** Whether an automatic run should apply the network to this member Node now. */
    public static function isDue(NodeCluster $cluster, Node $node): bool
    {
        if (is_array($node->network_pending_leave)) {
            return true;
        }

        return match ($cluster->nodeNetworkState($node)) {
            'converged' => false,
            'error' => $node->network_next_attempt_at === null || ! $node->network_next_attempt_at->isFuture(),
            default => true,
        };
    }

    /**
     * @param  list<int>|null  $nodeIds
     * @return list<NodeOperation>
     */
    private function reconcile(NodeCluster $cluster, User $user, ?array $nodeIds, bool $onlyDueNodes): array
    {
        $fullRun = $nodeIds === null;

        try {
            $members = $this->members($cluster);
            if ($members->isEmpty()) {
                throw new RuntimeException('Assign at least one server before network activation.');
            }
            if ($fullRun) {
                $cluster->update(['network_status' => 'reconciling']);
            }
            foreach ($members as $node) {
                if (blank($node->workload_cidr)) {
                    AssignNodeToCluster::run($cluster, $node);
                    $node->refresh();
                }
                foreach ($node->workloads as $workload) {
                    EnsureNodeWorkloadAddress::run($node, $workload);
                }
            }
            $cluster->refresh();
            $members = $this->members($cluster);

            $targets = $fullRun ? $members : $members->whereIn('id', $nodeIds)->values();
            if ($onlyDueNodes) {
                $targets = $targets->filter(fn (Node $node): bool => self::isDue($cluster, $node))->values();
            }

            $attempt = (string) Str::uuid();
            $operations = [];
            /** @var array<int, string> $failures */
            $failures = [];
            /** @var array<int, Node> $ready */
            $ready = [];
            $peerSetChanged = false;

            $prepare = function (Collection $nodes) use (&$operations, &$failures, &$ready, &$peerSetChanged, $cluster, $user, $attempt): void {
                foreach ($nodes as $node) {
                    if (isset($ready[$node->id]) || isset($failures[$node->id])) {
                        continue;
                    }
                    if (! $node->canReceiveNetworkCommands()) {
                        $this->markPending($cluster, $node);

                        continue;
                    }
                    try {
                        foreach (self::REQUIRED_CAPABILITIES as $capability) {
                            $node->ensureCapability($capability);
                        }
                        if (! LeaveNodeClusterNetwork::completePending($node)) {
                            throw new RuntimeException('The server is still leaving its previous cluster network.');
                        }
                        if (blank($node->wireguard_public_key)) {
                            $operations[] = $this->runOperation($node, $user, $attempt, 'network.wireguard.key.ensure.v1', [
                                'interface' => $cluster->wireguard_interface,
                            ]);
                            $node->refresh();
                            if (blank($node->wireguard_public_key)) {
                                throw new RuntimeException('The server did not report a WireGuard public key.');
                            }
                            $peerSetChanged = true;
                        }
                        $ready[$node->id] = $node;
                    } catch (Throwable $exception) {
                        $failures[$node->id] = $exception->getMessage();
                    }
                }
            };
            $prepare($targets);

            if ($peerSetChanged) {
                // A new peer joined the mesh: every other Node needs a new revision with it.
                $cluster->increment('desired_revision');
                $cluster->refresh();
                $prepare($this->members($cluster));
            }

            $members = $this->members($cluster);
            $peers = $members->filter(fn (Node $node): bool => filled($node->wireguard_public_key) && filled($node->wireguard_ip) && filled($node->workload_cidr));
            $fluxProbeHost = parse_url((string) config('constants.flux.public_url'), PHP_URL_HOST) ?: '';
            $firewallRules = $this->firewallRules($cluster);
            $ingressRules = $this->ingressRules($cluster);
            $workloadCidrs = $members->pluck('workload_cidr')->filter()->values()->all();
            $ingressRoutes = [];
            $workloadNames = [];
            $ingressRoutesError = null;
            try {
                $ingressRoutes = BuildNodeClusterIngressRoutes::run($cluster);
                $workloadNames = BuildNodeClusterIngressRoutes::names($cluster);
            } catch (RuntimeException $exception) {
                $ingressRoutesError = $exception->getMessage();
            }

            /** @var array<int, Node> $applied */
            $applied = [];
            foreach ($ready as $nodeId => $node) {
                try {
                    $nodePeers = $peers->where('id', '!=', $node->id)->values();
                    $operations[] = $this->runOperation($node, $user, $attempt, 'network.wireguard.reconcile.v1', [
                        'interface' => $cluster->wireguard_interface,
                        'address' => $node->wireguard_ip.'/32',
                        'listen_port' => $cluster->wireguard_port,
                        'revision' => $cluster->desired_revision,
                        'peers' => $nodePeers->map(fn (Node $peer): array => [
                            'public_key' => $peer->wireguard_public_key,
                            'endpoint' => $peer->wireguard_endpoint ?: $peer->ip.':'.$cluster->wireguard_port,
                            'allowed_ips' => [$peer->wireguard_ip.'/32', $peer->workload_cidr],
                            'persistent_keepalive_seconds' => 25,
                        ])->all(),
                        'flux_probe_host' => $fluxProbeHost,
                    ]);
                    $operations[] = $this->runOperation($node, $user, $attempt, 'network.firewall.reconcile.v1', [
                        'revision' => $cluster->desired_revision,
                        'wireguard_port' => $cluster->wireguard_port,
                        'wireguard_interface' => $cluster->wireguard_interface,
                        'cluster_cidr' => $cluster->cidr,
                        'local_node_ip' => $node->wireguard_ip,
                        'flux_probe_host' => $fluxProbeHost,
                        'workload_cidrs' => $workloadCidrs,
                        'rules' => $firewallRules,
                        'ingress_rules' => $ingressRules,
                    ]);
                    $operations[] = $this->runOperation($node, $user, $attempt, 'discovery.corrosion.reconcile.v1', [
                        'version' => self::CORROSION_VERSION,
                        'cluster_id' => $cluster->uuid,
                        'bind_address' => $node->wireguard_ip,
                        'node_dns_name' => $node->discoveryDnsName(),
                        'peers' => $nodePeers->map(fn (Node $peer): string => $peer->wireguard_ip.':8787')->all(),
                    ]);
                    $applied[$nodeId] = $node;
                } catch (Throwable $exception) {
                    $failures[$nodeId] = $exception->getMessage();
                }
            }

            // Corrosion only has to see the peers that can answer: Nodes applied in this run and
            // reachable Nodes that already converged.
            $healthy = collect(array_keys($applied))->merge(
                $members->filter(fn (Node $node): bool => ! isset($ready[$node->id]) && ! isset($failures[$node->id])
                    && $cluster->nodeNetworkState($node) === 'converged'
                    && $node->canReceiveNetworkCommands())->pluck('id'),
            )->unique();
            $converging = $applied;
            for ($inspection = 1; $inspection <= self::CORROSION_INSPECTIONS && $converging !== []; $inspection++) {
                foreach ($converging as $nodeId => $node) {
                    try {
                        $operation = $this->runOperation(
                            $node,
                            $user,
                            "{$attempt}:corrosion-inspection-{$inspection}",
                            'discovery.corrosion.inspect.v1',
                            [],
                        );
                        $operations[] = $operation;
                        $expectedPeers = $healthy->reject(fn (int $id): bool => $id === $nodeId)->count();
                        if (self::corrosionConverged($operation->result ?? [], $expectedPeers)) {
                            $node->refresh()->update(['corrosion_status' => 'converged']);
                            unset($converging[$nodeId]);
                        }
                    } catch (Throwable $exception) {
                        $failures[$nodeId] = $exception->getMessage();
                        unset($converging[$nodeId], $applied[$nodeId]);
                        $healthy = $healthy->reject(fn (int $id): bool => $id === $nodeId);
                    }
                }
                if ($converging !== [] && $inspection < self::CORROSION_INSPECTIONS) {
                    Sleep::usleep(500_000);
                }
            }
            foreach ($converging as $nodeId => $node) {
                $failures[$nodeId] = "Corrosion did not converge on server {$node->name}.";
                unset($applied[$nodeId]);
            }

            // Ingress is the last step: Caddy reads its routes and endpoints from a converged Corrosion.
            foreach ($applied as $nodeId => $node) {
                try {
                    $operations[] = $this->reconcileIngress($cluster, $node->refresh(), $user, $attempt, $ingressRoutes, $workloadNames, $ingressRoutesError);
                } catch (Throwable $exception) {
                    $failures[$nodeId] = $exception->getMessage();
                    unset($applied[$nodeId]);
                }
            }

            foreach ($applied as $node) {
                $node->refresh()->update([
                    'network_status' => 'converged',
                    'network_error' => null,
                    'network_attempts' => 0,
                    'network_next_attempt_at' => null,
                ]);
            }
            foreach ($failures as $nodeId => $message) {
                $this->markError(Node::query()->findOrFail($nodeId), $message);
            }

            $cluster->refresh();
            $status = $cluster->deriveNetworkStatus();
            $cluster->update(array_filter([
                // A Node-scoped run must not hide a queued full reconciliation.
                'network_status' => $fullRun || $cluster->network_status !== 'reconciling' ? $status : null,
                'last_reconciled_at' => $applied !== [] ? now() : null,
            ], fn ($value) => $value !== null));

            return $operations;
        } catch (Throwable $exception) {
            if ($fullRun) {
                $cluster->update(['network_status' => 'error']);
            }
            throw $exception;
        }
    }

    /**
     * Every Node gets the full route and internal name lists, because Sentinel writes them to
     * Corrosion from any Node. `enabled` only controls Caddy: Nodes that are not ingress Nodes
     * get `enabled: false`, so a Node that served ingress before removes Caddy.
     *
     * @param  list<array{host: string, workload_id: string, namespace: string, port: int}>  $routes
     * @param  list<array{name: string, workload_id: string, namespace: string}>  $names
     */
    private function reconcileIngress(NodeCluster $cluster, Node $node, User $user, string $attempt, array $routes, array $names, ?string $routesError): NodeOperation
    {
        // Without the full lists a Node would write an empty route and name table.
        if ($routesError !== null) {
            throw new RuntimeException($routesError);
        }

        return $this->runOperation($node, $user, $attempt, self::INGRESS_CAPABILITY, [
            'enabled' => (bool) $node->is_ingress,
            'caddy_version' => self::CADDY_VERSION,
            'revision' => $cluster->desired_revision,
            'routes' => $routes,
            'names' => $names,
        ]);
    }

    private function markPending(NodeCluster $cluster, Node $node): void
    {
        if ($cluster->nodeNetworkState($node) === 'converged') {
            return;
        }
        $node->refresh()->update(['network_status' => 'pending', 'network_error' => null]);
    }

    private function markError(Node $node, string $message): void
    {
        $attempts = $node->network_attempts + 1;
        $node->update([
            'network_status' => 'error',
            'network_error' => mb_substr($message, 0, 2000),
            'network_attempts' => $attempts,
            'network_next_attempt_at' => now()->addMinutes(min(2 ** min($attempts - 1, 10), self::MAX_BACKOFF_MINUTES)),
        ]);
    }

    /** @return list<array{source_ip: string, destination_ip: string, protocol: string, port: int}> */
    private function firewallRules(NodeCluster $cluster): array
    {
        return NodeFirewallRule::query()
            ->with(['sourceWorkload.nodes', 'sourceNode', 'destinationWorkload.nodes'])
            ->where('node_cluster_id', $cluster->id)
            ->get()
            ->flatMap(function (NodeFirewallRule $rule) use ($cluster) {
                $sources = $rule->sourceNode !== null
                    ? collect([$rule->sourceNode->wireguard_ip])->filter()
                    : $rule->sourceWorkload->nodes->where('node_cluster_id', $cluster->id)->pluck('pivot.container_ip')->filter();
                $destinations = $rule->destinationWorkload->nodes->where('node_cluster_id', $cluster->id)->pluck('pivot.container_ip')->filter();

                return $sources->crossJoin($destinations)->map(fn (array $addresses): array => [
                    'source_ip' => $addresses[0],
                    'destination_ip' => $addresses[1],
                    'protocol' => $rule->protocol,
                    'port' => $rule->port,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * One allow for each container that an ingress route reaches,
     * so Caddy on any ingress Node can connect to it.
     *
     * @return list<array{destination_ip: string, protocol: string, port: int}>
     */
    private function ingressRules(NodeCluster $cluster): array
    {
        return BuildNodeClusterIngressRoutes::routedWorkloads($cluster)
            ->flatMap(fn (NodeWorkload $workload) => $workload->nodes
                ->pluck('pivot.container_ip')
                ->filter()
                ->map(fn (string $destinationIp): array => [
                    'destination_ip' => $destinationIp,
                    'protocol' => 'tcp',
                    'port' => $workload->http_port,
                ]))
            ->unique(fn (array $rule): string => "{$rule['destination_ip']}|{$rule['protocol']}|{$rule['port']}")
            ->values()
            ->all();
    }

    /** @return Collection<int, Node> */
    private function members(NodeCluster $cluster): Collection
    {
        return Node::query()
            ->where('team_id', $cluster->team_id)
            ->where('node_cluster_id', $cluster->id)
            ->orderBy('id')
            ->get();
    }

    /** @param array<string, mixed> $payload */
    private function runOperation(Node $node, User $user, string $attempt, string $commandType, array $payload): NodeOperation
    {
        $operation = CreateOperation::run(
            $node,
            $commandType,
            "cluster-network:{$attempt}:{$commandType}",
            request: $payload,
            requestedBy: $user,
        );

        try {
            TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
            $result = $this->dispatch($operation);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            $this->recordObservedState($operation->node, $commandType, $result);

            return TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
        } catch (Throwable $exception) {
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function dispatch(NodeOperation $operation): array
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || blank($url) || ! is_string($token) || blank($token)) {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }
        $path = Str::beforeLast($operation->command_type, '.v1');
        $response = Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout(180)
            ->post(rtrim($url, '/').'/v1/commands/'.$path, [
                'server_id' => $operation->node->uuid,
                'command_id' => $operation->uuid,
                ...$operation->request,
            ]);
        $response->throw();
        $result = $response->json();
        if (! is_array($result) || data_get($result, 'command_id') !== $operation->uuid || ! is_numeric(data_get($result, 'observed_at_unix_ms'))) {
            throw new RuntimeException('Flux returned an invalid network command result.');
        }
        $this->validateResult($operation, $result);

        return $result;
    }

    /** @param array<string, mixed> $result */
    private function validateResult(NodeOperation $operation, array $result): void
    {
        $valid = match ($operation->command_type) {
            'network.wireguard.key.ensure.v1' => filled(data_get($result, 'public_key')),
            'network.wireguard.reconcile.v1' => data_get($result, 'rollback_cancelled') === true
                && data_get($result, 'drifted') === false
                && data_get($result, 'applied_revision') === data_get($operation->request, 'revision')
                && data_get($result, 'listen_port') === data_get($operation->request, 'listen_port')
                && filled(data_get($result, 'public_key'))
                && count(data_get($result, 'peers', [])) === count(data_get($operation->request, 'peers', [])),
            'network.firewall.reconcile.v1' => data_get($result, 'rollback_cancelled') === true
                && data_get($result, 'ingress_enforced') === true
                && data_get($result, 'drifted') === false
                && data_get($result, 'applied_revision') === data_get($operation->request, 'revision')
                && data_get($result, 'table') === 'coolify_cluster',
            'discovery.corrosion.reconcile.v1' => data_get($result, 'version') === self::CORROSION_VERSION
                && in_array(data_get($result, 'member_state'), ['configured', 'joining', 'converged'], true)
                && is_numeric(data_get($result, 'endpoint_count')),
            'discovery.corrosion.inspect.v1' => data_get($result, 'version') === self::CORROSION_VERSION
                && in_array(data_get($result, 'member_state'), ['joining', 'converged'], true)
                && is_numeric(data_get($result, 'endpoint_count')),
            self::INGRESS_CAPABILITY => data_get($result, 'enabled') === data_get($operation->request, 'enabled')
                && data_get($result, 'revision') === data_get($operation->request, 'revision')
                && data_get($result, 'route_count') === count(data_get($operation->request, 'routes', []))
                && data_get($result, 'name_count') === count(data_get($operation->request, 'names', []))
                && (data_get($operation->request, 'enabled') !== true || data_get($result, 'active') === true),
            default => false,
        };

        if (! $valid) {
            throw new RuntimeException('Flux returned unsafe or incomplete network state.');
        }
    }

    /** @param array<string, mixed> $result */
    private function recordObservedState(Node $node, string $commandType, array $result): void
    {
        match ($commandType) {
            'network.wireguard.key.ensure.v1' => $node->update(['wireguard_public_key' => data_get($result, 'public_key')]),
            'network.wireguard.reconcile.v1' => $node->update([
                'wireguard_public_key' => data_get($result, 'public_key', $node->wireguard_public_key),
                'network_applied_revision' => data_get($result, 'applied_revision'),
                'network_observed_state' => $result,
                'wireguard_last_handshake_at' => collect(data_get($result, 'peers', []))->max('latest_handshake_unix_seconds')
                    ? now()->setTimestamp((int) collect(data_get($result, 'peers', []))->max('latest_handshake_unix_seconds'))
                    : null,
            ]),
            'discovery.corrosion.reconcile.v1', 'discovery.corrosion.inspect.v1' => $node->mergeMetadata([
                'corrosion_endpoint_count' => (int) data_get($result, 'endpoint_count', 0),
                'corrosion_last_convergence_unix_seconds' => data_get($result, 'last_convergence_unix_seconds'),
            ], [
                'corrosion_status' => data_get($result, 'member_state'),
                'corrosion_version' => data_get($result, 'version'),
            ]),
            'network.firewall.reconcile.v1' => $node->mergeMetadata([
                'firewall_applied_revision' => data_get($result, 'applied_revision'),
                'firewall_configuration_hash' => data_get($result, 'configuration_hash'),
            ]),
            self::INGRESS_CAPABILITY => $node->mergeMetadata([
                'ingress_enabled' => data_get($result, 'enabled'),
                'ingress_active' => data_get($result, 'active'),
                'ingress_applied_revision' => data_get($result, 'revision'),
                'ingress_route_count' => data_get($result, 'route_count'),
                'ingress_name_count' => data_get($result, 'name_count'),
                'ingress_caddy_version' => data_get($result, 'caddy_version'),
            ]),
            default => null,
        };
    }
}
