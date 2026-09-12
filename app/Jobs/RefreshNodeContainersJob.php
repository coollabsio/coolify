<?php

namespace App\Jobs;

use App\Actions\Node\FetchContainers;
use App\Models\Node;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshNodeContainersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $nodeId) {}

    public function uniqueId(): string
    {
        return (string) $this->nodeId;
    }

    public function handle(): void
    {
        $node = Node::query()->where('is_usable', true)->find($this->nodeId);
        if ($node === null || ! $node->hasRecentFluxHeartbeat()) {
            return;
        }

        FetchContainers::run($node);
    }
}
