<?php

namespace App\Jobs;

use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Models\NodeCluster;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ReconcileNodeClusterNetworkJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $clusterId, public int $userId) {}

    public function handle(): void
    {
        $cluster = NodeCluster::query()->findOrFail($this->clusterId);
        $user = User::query()->findOrFail($this->userId);

        ReconcileNodeClusterNetwork::run($cluster, $user);
    }

    public function failed(?Throwable $exception): void
    {
        NodeCluster::query()->whereKey($this->clusterId)->update(['network_status' => 'error']);
    }
}
