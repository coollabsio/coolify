<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeCluster;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncNodeReachability
{
    use AsAction;

    public function handle(Node $node): bool
    {
        $isReachable = $node->hasRecentFluxHeartbeat();

        if ($node->is_reachable !== $isReachable) {
            $node->update(['is_reachable' => $isReachable]);
        }

        if (! $isReachable) {
            $connection = Cache::get($node->cacheKey());
            if (is_array($connection) && data_get($connection, 'status') === 'connected') {
                Cache::put($node->cacheKey(), [
                    ...$connection,
                    'status' => 'unavailable',
                ], now()->addMinutes(5));
            }
        }

        if ($node->is_ingress && $node->cluster !== null) {
            $this->moveTlsIssuer($node->cluster);
        }
        // The registry moves when its Node goes offline, unless the user selected that Node.
        if ($node->cluster?->syncRegistryNode()) {
            QueueNodeClusterNetworkRevision::run($node->cluster);
        }

        return $isReachable;
    }

    /**
     * Queues a network revision when the certificate issuer must change: the issuer went offline,
     * came back, or HTTPS became available or unavailable for the cluster.
     */
    private function moveTlsIssuer(NodeCluster $cluster): void
    {
        $issuerId = $cluster->supportsIngressTls() ? $cluster->tlsIssuerNode()?->id : null;
        if ($cluster->ingress_tls_issuer_node_id === $issuerId) {
            return;
        }
        // Recorded now, so the next check does not queue again before the run applies it.
        $cluster->update(['ingress_tls_issuer_node_id' => $issuerId]);
        QueueNodeClusterNetworkRevision::run($cluster);
    }
}
