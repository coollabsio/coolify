<?php

namespace App\Jobs;

use App\Actions\Node\SyncNodeWorkloadSet;
use App\Models\Node;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Compares the generation and the hash of the desired set that Sentinel reported in its `Hello` or
 * heartbeat with Postgres (decision 0026). A difference sends the full set. After a reconnect, an
 * equal set is confirmed, so Sentinel can deploy and remove again.
 */
class SyncNodeWorkloadSetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 60;

    public function __construct(public int $nodeId, public bool $reconnected = false) {}

    public function uniqueId(): string
    {
        return $this->nodeId.($this->reconnected ? ':connected' : '');
    }

    public function handle(): void
    {
        $node = Node::query()->find($this->nodeId);
        if ($node === null || $node->supportsCapability(SyncNodeWorkloadSet::CAPABILITY) !== true) {
            return;
        }
        $connection = Cache::get($node->cacheKey(), []);
        $reportedGeneration = data_get($connection, 'workload_generation');
        $reportedHash = (string) data_get($connection, 'workload_set_hash', '');
        if ($reportedGeneration === null) {
            return;
        }
        // The desired hash after the entries of workloads that left the Node are gone (the fence).
        SyncNodeWorkloadSet::make()->refreshHash($node);
        $node->refresh();
        $matches = (int) $reportedGeneration === (int) $node->workload_set_generation
            && ($reportedHash === (string) $node->workload_set_hash || ((int) $reportedGeneration === 0 && $node->workload_set_hash === hash('sha256', '')));
        if (! $matches) {
            SyncNodeWorkloadSet::run($node);
        } elseif ($this->reconnected) {
            SyncNodeWorkloadSet::run($node, confirm: true);
        }
    }
}
