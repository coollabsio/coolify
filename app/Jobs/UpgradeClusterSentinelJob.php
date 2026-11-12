<?php

namespace App\Jobs;

use App\Actions\Node\UpgradeSentinel;
use App\Models\NodeCluster;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Upgrades the cluster's Nodes one at a time and stops at the first failure. */
class UpgradeClusterSentinelJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Up to 18 minutes per Node (see UpgradeNodeSentinelJob), so about 20 Nodes. */
    public int $timeout = 21600;

    public int $uniqueFor = 21600;

    public function __construct(public int $clusterId, public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->clusterId;
    }

    public function handle(): void
    {
        $cluster = NodeCluster::query()->findOrFail($this->clusterId);
        $user = User::query()->findOrFail($this->userId);
        UpgradeSentinel::make()->upgradeCluster($cluster, $user);
    }

    public function failed(?Throwable $exception): void
    {
        $key = UpgradeSentinel::clusterUpgradeCacheKey($this->clusterId);
        $run = Cache::get($key, []);
        Cache::put($key, [
            'nodes' => [],
            ...$run,
            'status' => 'failed',
            'error' => data_get($run, 'error') ?? 'The Sentinel upgrade stopped before completion. Check the Sentinel version on each server.',
            'finished_at' => now()->toIso8601String(),
        ], now()->addDay());
    }
}
