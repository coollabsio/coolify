<?php

namespace App\Jobs;

use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Models\NodeCluster;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class ReconcileNodeClusterNetworkJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Waiting for another network run of the cluster releases the job; any exception fails it. */
    public int $maxExceptions = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /** @param list<int>|null $nodeIds Null reconciles every member Node. */
    public function __construct(
        public int $clusterId,
        public int $userId,
        public ?array $nodeIds = null,
        public bool $onlyDueNodes = false,
    ) {}

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(30);
    }

    public function handle(): void
    {
        $cluster = NodeCluster::query()->find($this->clusterId);
        if ($cluster === null) {
            return;
        }
        $user = User::query()->findOrFail($this->userId);

        try {
            ReconcileNodeClusterNetwork::run($cluster, $user, $this->nodeIds, $this->onlyDueNodes);
        } catch (LockTimeoutException) {
            $this->release(15);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->nodeIds === null) {
            NodeCluster::query()->whereKey($this->clusterId)->update(['network_status' => 'error']);
        }
    }
}
