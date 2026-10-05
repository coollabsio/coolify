<?php

namespace App\Actions\Node;

use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

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

        $hadAppliedNetwork = $node->network_applied_revision !== null || in_array($cluster->network_status, ['active', 'degraded'], true);
        $leaveRequest = [
            'interface' => $cluster->wireguard_interface,
            'owner_node_ip' => $node->wireguard_ip,
            'workload_cidrs' => $cluster->nodes->pluck('workload_cidr')->filter()->values()->all(),
        ];
        $leaveDeferred = false;
        if ($hadAppliedNetwork) {
            if ($node->canReceiveNetworkCommands()) {
                LeaveNodeClusterNetwork::run($node, $leaveRequest);
            } else {
                // The Node is offline: remove it now and tear its network down when it reconnects.
                $leaveDeferred = true;
            }
        }

        $node = DB::transaction(function () use ($cluster, $node, $leaveDeferred, $leaveRequest): Node {
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
                'network_status' => null,
                'network_error' => null,
                'network_attempts' => 0,
                'network_next_attempt_at' => null,
                'network_pending_leave' => $leaveDeferred ? $leaveRequest : $node->network_pending_leave,
                'is_ingress' => false,
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
}
