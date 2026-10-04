<?php

namespace App\Jobs;

use App\Actions\Node\LeaveNodeClusterNetwork;
use App\Models\Node;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Tears down the network of a Node that left its cluster while it was offline. */
class CompleteNodeClusterLeaveJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $nodeId) {}

    public function uniqueId(): string
    {
        return (string) $this->nodeId;
    }

    public function handle(): void
    {
        $node = Node::query()->find($this->nodeId);
        if ($node === null) {
            return;
        }

        LeaveNodeClusterNetwork::completePending($node);
    }
}
