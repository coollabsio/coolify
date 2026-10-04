<?php

namespace App\Jobs;

use App\Actions\Node\NotifyNodeClusterNetworkHealth;
use App\Actions\Node\NotifyNodeReachability;
use App\Models\Node;
use App\Models\NodeCluster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyNodeHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        Node::query()
            ->where('is_usable', true)
            ->chunkById(500, function ($nodes): void {
                foreach ($nodes as $node) {
                    NotifyNodeReachability::run($node);
                }
            });

        NodeCluster::query()
            ->where(fn ($query) => $query
                ->whereNotNull('network_unhealthy_notified_at')
                ->orWhereIn('network_status', NotifyNodeClusterNetworkHealth::UNHEALTHY_STATUSES))
            ->chunkById(100, function ($clusters): void {
                foreach ($clusters as $cluster) {
                    NotifyNodeClusterNetworkHealth::run($cluster);
                }
            });
    }
}
