<?php

namespace App\Services\DeployTriggers;

use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * Limits concurrent push evaluations on self-hosted instances, which share a small worker pool.
 */
class EvaluationSlots
{
    public const LIMIT = 2;

    public function run(Closure $callback, Closure $whenBusy): void
    {
        if (isCloud()) {
            $callback();

            return;
        }

        Redis::funnel('push-evaluation')->limit(self::LIMIT)->block(0)->then($callback, $whenBusy);
    }
}
