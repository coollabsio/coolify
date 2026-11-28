<?php

namespace App\Services\DeployTriggers;

use App\Jobs\EvaluatePush;
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

        // A slot is released after 60 seconds by default, long before an evaluation ends.
        Redis::funnel('push-evaluation')->limit(self::LIMIT)->releaseAfter(get_class_vars(EvaluatePush::class)['timeout'])->block(0)->then($callback, $whenBusy);
    }
}
