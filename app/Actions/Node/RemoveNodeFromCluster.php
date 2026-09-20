<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class RemoveNodeFromCluster
{
    use AsAction;

    public function handle(NodeCluster $cluster, Node $node, User $user, bool $reconcileSurvivors = true): Node
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $cluster->loadMissing('nodes');
        if ($node->node_cluster_id !== $cluster->id || $node->team_id !== $cluster->team_id) {
            throw new DomainException('The Node does not belong to this cluster.');
        }
        if ($node->containers()->where('is_managed', true)->whereIn('state', ['configured', 'created', 'running', 'paused', 'restarting', 'removing'])->exists()) {
            throw new DomainException('Stop and remove managed containers from this Node before removing it from the cluster.');
        }

        $hadAppliedNetwork = $node->network_applied_revision !== null || $cluster->network_status === 'active';
        if ($hadAppliedNetwork) {
            $node->ensureCapability('discovery.corrosion.endpoints.reconcile.v1');
            $node->ensureCapability('network.cluster.leave.v1');
            $this->dispatch($node, 'discovery.corrosion.endpoints.reconcile.v1', [
                'owner_node_ip' => $node->wireguard_ip,
                'endpoints' => [],
            ]);
            $this->dispatch($node, 'network.cluster.leave.v1', [
                'interface' => $cluster->wireguard_interface,
                'owner_node_ip' => $node->wireguard_ip,
                'workload_cidrs' => $cluster->nodes->pluck('workload_cidr')->filter()->values()->all(),
            ]);
        }

        $node = DB::transaction(function () use ($cluster, $node): Node {
            $cluster = NodeCluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->node_cluster_id !== $cluster->id) {
                throw new DomainException('The Node does not belong to this cluster.');
            }

            NodeFirewallRule::query()->where('source_node_id', $node->id)->delete();
            $node->workloads()->detach();
            $node->update([
                'node_cluster_id' => null,
                'wireguard_ip' => null,
                'wireguard_public_key' => null,
                'wireguard_endpoint' => null,
                'workload_cidr' => null,
                'network_applied_revision' => null,
                'network_observed_state' => null,
                'wireguard_last_handshake_at' => null,
                'corrosion_status' => null,
                'corrosion_version' => null,
            ]);
            $cluster->increment('desired_revision');
            $cluster->update(['network_status' => $cluster->nodes()->exists() ? 'reconciling' : 'pending']);

            return $node;
        });

        if ($reconcileSurvivors && $cluster->nodes()->exists()) {
            ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $user->id)->afterCommit();
        }

        return $node;
    }

    /** @param array<string, mixed> $request */
    private function dispatch(Node $node, string $commandType, array $request): void
    {
        $operation = CreateOperation::run($node, $commandType, 'node-removal:'.Str::uuid(), request: $request);

        try {
            TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
            $url = config('constants.flux.internal_url');
            $token = config('constants.flux.internal_token');
            if (! is_string($url) || blank($url) || ! is_string($token) || blank($token)) {
                throw new RuntimeException('Flux internal API configuration is incomplete.');
            }
            $path = Str::beforeLast($commandType, '.v1');
            $response = Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout(180)
                ->post(rtrim($url, '/').'/v1/commands/'.$path, [
                    'server_id' => $node->uuid,
                    'command_id' => $operation->uuid,
                    ...$request,
                ]);
            $response->throw();
            $result = $response->json();
            $valid = is_array($result)
                && data_get($result, 'command_id') === $operation->uuid
                && is_numeric(data_get($result, 'observed_at_unix_ms'))
                && match ($commandType) {
                    'discovery.corrosion.endpoints.reconcile.v1' => data_get($result, 'owner_node_ip') === $node->wireguard_ip
                        && data_get($result, 'endpoint_count') === 0,
                    'network.cluster.leave.v1' => data_get($result, 'wireguard_removed') === true
                        && data_get($result, 'firewall_removed') === true
                        && data_get($result, 'discovery_removed') === true
                        && data_get($result, 'resolver_reverted') === true,
                    default => false,
                };
            if (! $valid) {
                throw new RuntimeException('Flux returned an invalid Node cleanup result.');
            }
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
        } catch (Throwable $exception) {
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }
            throw $exception;
        }
    }
}
