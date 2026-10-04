<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeCluster;
use App\Notifications\Node\ClusterNetworkRecovered;
use App\Notifications\Node\ClusterNetworkUnhealthy;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Notifies the owning team once when a cluster network becomes unhealthy and
 * once when it is active again. Transient `reconciling` and `pending` states
 * keep the current notification state, so a failed retry does not notify twice.
 */
class NotifyNodeClusterNetworkHealth
{
    use AsAction;

    public const UNHEALTHY_STATUSES = ['error', 'failed', 'degraded'];

    public const HEALTHY_STATUSES = ['active', 'applied'];

    public function handle(NodeCluster $cluster): void
    {
        $status = (string) $cluster->network_status;

        if (in_array($status, self::UNHEALTHY_STATUSES, true)) {
            $this->handleUnhealthy($cluster, $status);

            return;
        }

        if ($cluster->network_unhealthy_notified_at === null) {
            return;
        }

        if (in_array($status, self::HEALTHY_STATUSES, true)) {
            if ($this->clearNotifiedState($cluster)) {
                $cluster->team?->notify(new ClusterNetworkRecovered($cluster));
            }

            return;
        }

        // A cluster without Nodes ends the outage without a recovery message.
        if ($status === 'pending' && ! $cluster->nodes()->exists()) {
            $this->clearNotifiedState($cluster);
        }
    }

    private function handleUnhealthy(NodeCluster $cluster, string $status): void
    {
        if ($cluster->network_unhealthy_notified_at !== null) {
            return;
        }

        $claimed = NodeCluster::query()
            ->whereKey($cluster->getKey())
            ->whereNull('network_unhealthy_notified_at')
            ->update(['network_unhealthy_notified_at' => now()]);

        if ($claimed === 1) {
            $cluster->team?->notify(new ClusterNetworkUnhealthy($cluster, $status, $this->affectedNodeNames($cluster)));
        }
    }

    private function clearNotifiedState(NodeCluster $cluster): bool
    {
        return NodeCluster::query()
            ->whereKey($cluster->getKey())
            ->whereNotNull('network_unhealthy_notified_at')
            ->update(['network_unhealthy_notified_at' => null]) === 1;
    }

    /** @return list<string> */
    private function affectedNodeNames(NodeCluster $cluster): array
    {
        return $cluster->nodes()
            ->orderBy('name')
            ->get()
            ->reject(fn (Node $node): bool => $cluster->isNodeNetworkInSync($node))
            ->pluck('name')
            ->values()
            ->all();
    }
}
