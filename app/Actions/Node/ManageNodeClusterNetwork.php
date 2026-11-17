<?php

namespace App\Actions\Node;

use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Jobs\UpgradeClusterSentinelJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * The recovery actions of a cluster, for the cluster page and the API (decision 0029): reconcile
 * the network, repair the network over SSH, and upgrade Sentinel on the servers one at a time
 * (decision 0013). Only a team admin may run them (`update` on the cluster).
 */
class ManageNodeClusterNetwork
{
    use AsAction;

    /**
     * Queues a reconciliation of the network.
     *
     * @return bool False when a reconciliation runs already.
     *
     * @throws RuntimeException When the cluster has no server.
     */
    public function reconcile(NodeCluster $cluster, User $user): bool
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $cluster->refresh();
        if ($cluster->network_status === 'reconciling') {
            return false;
        }
        if (! $cluster->nodes()->exists()) {
            throw new RuntimeException('Assign at least one server before network activation.');
        }
        $cluster->update(['network_status' => 'reconciling']);
        ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $user->id);

        return true;
    }

    /**
     * Restores the last good network state on each server over SSH. The network is then
     * `pending` until the next reconciliation verifies it.
     *
     * @throws LockTimeoutException When a reconciliation runs.
     * @throws RuntimeException When a server could not be repaired.
     */
    public function repair(NodeCluster $cluster, User $user): void
    {
        Gate::forUser($user)->authorize('update', $cluster);
        RepairNodeClusterNetwork::run($cluster);
        $cluster->update(['network_status' => 'pending']);
    }

    /**
     * Queues the upgrade of Sentinel on the servers of the cluster that run an older version.
     *
     * @return Collection<int, Node> The servers to upgrade, in order. Empty when each one is up to date.
     *
     * @throws RuntimeException When an upgrade of this cluster runs already.
     */
    public function upgradeSentinel(NodeCluster $cluster, User $user): Collection
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $release = FetchLatestSentinelRelease::run();
        $nodes = UpgradeSentinel::clusterNodesToUpgrade($cluster, $release);
        foreach ($nodes as $node) {
            Gate::forUser($user)->authorize('manageSentinel', $node);
        }
        if ($nodes->isEmpty()) {
            return $nodes;
        }
        $key = UpgradeSentinel::clusterUpgradeCacheKey($cluster->id);
        if (in_array(data_get(Cache::get($key), 'status'), ['queued', 'running'], true)) {
            throw new RuntimeException('A Sentinel upgrade is already running in this cluster.');
        }

        Cache::put($key, UpgradeSentinel::newClusterRun('queued', $release['version'], $nodes), now()->addDay());
        UpgradeClusterSentinelJob::dispatch($cluster->id, $user->id);

        return $nodes;
    }
}
