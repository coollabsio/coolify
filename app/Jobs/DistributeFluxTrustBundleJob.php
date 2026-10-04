<?php

namespace App\Jobs;

use App\Actions\Sentinel\DistributeFluxTrustBundle;
use App\Actions\Sentinel\ResolveFluxTrustBundle;
use App\Models\Node;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver the current Flux trust bundle to one Node, or fan out to every Node
 * that has not acknowledged it yet when no Node is given.
 */
class DistributeFluxTrustBundleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public ?int $nodeId = null) {}

    public function uniqueId(): string
    {
        return $this->nodeId === null ? 'all' : (string) $this->nodeId;
    }

    public function handle(): void
    {
        if (! config('constants.sentinel.host_enabled', false)) {
            return;
        }

        if ($this->nodeId !== null) {
            $node = Node::query()->find($this->nodeId);
            if ($node !== null) {
                DistributeFluxTrustBundle::run($node);
            }

            return;
        }

        $version = ResolveFluxTrustBundle::run()['version'];
        Node::query()
            ->where(fn ($query) => $query->whereNull('flux_trust_bundle_version')->orWhere('flux_trust_bundle_version', '<', $version))
            ->select(['id', 'uuid'])
            ->chunkById(500, function ($nodes): void {
                foreach ($nodes as $node) {
                    if ($node->hasRecentFluxHeartbeat()) {
                        self::dispatch($node->id);
                    }
                }
            });
    }
}
