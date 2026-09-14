<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeIngressRule;
use App\Models\NodeOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class ReconcileNodeClusterNetwork
{
    use AsAction;

    private const CORROSION_VERSION = 'v1.0.0';

    /** @return list<NodeOperation> */
    public function handle(NodeCluster $cluster, User $user): array
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $attempt = (string) Str::uuid();
        $cluster->update(['network_status' => 'reconciling']);

        try {
            $nodes = $this->lockedNodes($cluster);
            if ($nodes->isEmpty()) {
                throw new RuntimeException('Assign at least one Node before network activation.');
            }
            foreach ($nodes as $node) {
                if (blank($node->workload_cidr)) {
                    AssignNodeToCluster::run($cluster, $node);
                    $node->refresh();
                }
                foreach ($node->workloads as $workload) {
                    EnsureNodeWorkloadAddress::run($node, $workload);
                }
            }
            $nodes = $this->lockedNodes($cluster);

            $operations = [];
            foreach ($nodes as $node) {
                $operations[] = $this->runOperation($node, $user, $attempt, 'network.wireguard.key.ensure.v1', [
                    'interface' => $cluster->wireguard_interface,
                ]);
            }

            $nodes = $this->lockedNodes($cluster);
            if ($nodes->contains(fn (Node $node): bool => blank($node->wireguard_public_key))) {
                throw new RuntimeException('Every Node must report a WireGuard public key.');
            }
            $fluxProbeHost = parse_url((string) config('constants.flux.public_url'), PHP_URL_HOST) ?: '';
            foreach ($nodes as $node) {
                $peers = $nodes->where('id', '!=', $node->id)->map(fn (Node $peer): array => [
                    'public_key' => $peer->wireguard_public_key,
                    'endpoint' => $peer->wireguard_endpoint ?: $peer->ip.':'.$cluster->wireguard_port,
                    'allowed_ips' => [$peer->wireguard_ip.'/32', $peer->workload_cidr],
                    'persistent_keepalive_seconds' => 25,
                ])->values()->all();
                $operations[] = $this->runOperation($node, $user, $attempt, 'network.wireguard.reconcile.v1', [
                    'interface' => $cluster->wireguard_interface,
                    'address' => $node->wireguard_ip.'/32',
                    'listen_port' => $cluster->wireguard_port,
                    'revision' => $cluster->desired_revision,
                    'peers' => $peers,
                    'flux_probe_host' => $fluxProbeHost,
                ]);
            }

            foreach ($nodes as $node) {
                $operations[] = $this->runOperation($node, $user, $attempt, 'network.firewall.reconcile.v1', [
                    'revision' => $cluster->desired_revision,
                    'wireguard_port' => $cluster->wireguard_port,
                    'wireguard_interface' => $cluster->wireguard_interface,
                    'cluster_cidr' => $cluster->cidr,
                    'local_node_ip' => $node->wireguard_ip,
                    'flux_probe_host' => $fluxProbeHost,
                    'workload_cidrs' => $nodes->pluck('workload_cidr')->filter()->values()->all(),
                    'rules' => $this->firewallRules($cluster),
                    'ingress_rules' => $this->ingressRules($cluster),
                ]);
            }

            foreach ($nodes as $node) {
                $operations[] = $this->runOperation($node, $user, $attempt, 'discovery.corrosion.reconcile.v1', [
                    'version' => self::CORROSION_VERSION,
                    'cluster_id' => $cluster->uuid,
                    'bind_address' => $node->wireguard_ip,
                    'peers' => $nodes->where('id', '!=', $node->id)->pluck('wireguard_ip')->map(fn (string $ip): string => $ip.':8787')->values()->all(),
                ]);
            }

            foreach ($nodes as $node) {
                $converged = false;
                for ($inspection = 1; $inspection <= 20; $inspection++) {
                    $operation = $this->runOperation(
                        $node,
                        $user,
                        "{$attempt}:corrosion-inspection-{$inspection}",
                        'discovery.corrosion.inspect.v1',
                        [],
                    );
                    $operations[] = $operation;
                    if (data_get($operation->result, 'member_state') === 'converged') {
                        $converged = true;
                        break;
                    }
                    usleep(500_000);
                }
                if (! $converged) {
                    throw new RuntimeException("Corrosion did not converge on Node {$node->name}.");
                }
            }

            $cluster->update(['network_status' => 'active', 'last_reconciled_at' => now()]);

            return $operations;
        } catch (Throwable $exception) {
            $cluster->update(['network_status' => 'error']);
            throw $exception;
        }
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

    /** @return list<array{destination_ip: string, protocol: string, port: int}> */
    private function ingressRules(NodeCluster $cluster): array
    {
        return NodeIngressRule::query()
            ->with('destinationWorkload.nodes')
            ->where('node_cluster_id', $cluster->id)
            ->get()
            ->flatMap(fn (NodeIngressRule $rule) => $rule->destinationWorkload->nodes
                ->where('node_cluster_id', $cluster->id)
                ->pluck('pivot.container_ip')
                ->filter()
                ->map(fn (string $destinationIp): array => [
                    'destination_ip' => $destinationIp,
                    'protocol' => $rule->protocol,
                    'port' => $rule->port,
                ]))
            ->values()
            ->all();
    }

    /** @return Collection<int, Node> */
    private function lockedNodes(NodeCluster $cluster): Collection
    {
        return DB::transaction(fn () => Node::query()
            ->where('team_id', $cluster->team_id)
            ->where('node_cluster_id', $cluster->id)
            ->lockForUpdate()
            ->orderBy('id')
            ->get());
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
            'discovery.corrosion.reconcile.v1', 'discovery.corrosion.inspect.v1' => $node->update([
                'corrosion_status' => data_get($result, 'member_state'),
                'corrosion_version' => data_get($result, 'version'),
                'metadata' => [
                    ...($node->fresh()->metadata ?? []),
                    'corrosion_endpoint_count' => (int) data_get($result, 'endpoint_count', 0),
                    'corrosion_last_convergence_unix_seconds' => data_get($result, 'last_convergence_unix_seconds'),
                ],
            ]),
            default => null,
        };
    }
}
