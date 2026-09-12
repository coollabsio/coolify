<?php

namespace App\Jobs;

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
            ->select(['id', 'uuid'])
            ->chunkById(500, function ($nodes): void {
                foreach ($nodes as $node) {
                    if ($node->hasRecentFluxHeartbeat()) {
                        RefreshNodeContainersJob::dispatch($node->id)
                            ->delay(now()->addSeconds(random_int(0, 59)));
                    }
                }
            });
    }
}
