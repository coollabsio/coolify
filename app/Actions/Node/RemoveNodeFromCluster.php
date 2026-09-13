<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeCluster;
use DomainException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RemoveNodeFromCluster
{
    use AsAction;

    public function handle(NodeCluster $cluster, Node $node): Node
    {
        return DB::transaction(function () use ($cluster, $node): Node {
            $cluster = NodeCluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->node_cluster_id !== $cluster->id) {
                throw new DomainException('The Node does not belong to this cluster.');
            }

            $node->update([
                'node_cluster_id' => null,
                'wireguard_ip' => null,
                'wireguard_public_key' => null,
                'wireguard_endpoint' => null,
                'network_applied_revision' => null,
                'network_observed_state' => null,
                'wireguard_last_handshake_at' => null,
                'corrosion_status' => null,
                'corrosion_version' => null,
            ]);
            $cluster->increment('desired_revision');

            return $node;
        });
    }
}
