<?php

namespace App\Jobs;

use App\Actions\Node\FenceRecoveredNodeWorkloads;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\ReconcileNodeWorkloads;
use App\Models\Node;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Reads the container inventory of a Node. The lock is released when the job starts, so a status
 * event during a run queues one more refresh. A refresh for a status event or a Podman event (`runtime_changed`) has its own key, so the
 * delayed refresh of each minute does not hide it. Runs for the same Node never overlap: a waiting
 * run is released again, which is not a failure.
 */
class RefreshNodeContainersJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public int $maxExceptions = 1;

    public int $timeout = 30;

    /** Not promoted: a job queued before this property existed reads the default. */
    public bool $forStatusEvent = false;

    public function __construct(public int $nodeId, bool $forStatusEvent = false)
    {
        $this->forStatusEvent = $forStatusEvent;
    }

    public function uniqueId(): string
    {
        return $this->nodeId.($this->forStatusEvent ? ':status-event' : '');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('refresh-node-containers-'.$this->nodeId))->releaseAfter(2)->expireAfter(60)];
    }

    public function handle(): void
    {
        $node = Node::query()->where('is_usable', true)->find($this->nodeId);
        if ($node === null || ! $node->hasRecentFluxHeartbeat()) {
            return;
        }

        FetchContainers::run($node);
        FenceRecoveredNodeWorkloads::run($node);
        ReconcileNodeWorkloads::run($node);
    }
}
