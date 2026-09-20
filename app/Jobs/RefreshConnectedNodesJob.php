<?php

namespace App\Jobs;

use App\Actions\Node\SyncNodeReachability;
use App\Models\Node;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshConnectedNodesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        Node::query()
            ->where('is_usable', true)
            ->select(['id', 'uuid', 'is_reachable'])
            ->chunkById(500, function ($nodes): void {
                foreach ($nodes as $node) {
                    if (SyncNodeReachability::run($node)) {
                        $delay = now()->addSeconds(random_int(0, 59));
                        RefreshNodeContainersJob::dispatch($node->id)
                            ->delay($delay);
                        RefreshNodeInformationJob::dispatch($node->id)
                            ->delay($delay);
                    }
                }
            });
    }
}
