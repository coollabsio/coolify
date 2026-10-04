<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Notifications\Node\Reachable;
use App\Notifications\Node\Unreachable;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Notifies the owning team once per outage when a Node has had no Flux
 * heartbeat for longer than the grace period, and once when it recovers.
 * The grace period absorbs Sentinel restarts and upgrades, which reconnect
 * within about a minute.
 */
class NotifyNodeReachability
{
    use AsAction;

    public const GRACE_PERIOD_SECONDS = 180;

    public function handle(Node $node): void
    {
        if ($node->hasRecentFluxHeartbeat()) {
            $this->handleReachable($node);

            return;
        }

        $this->handleUnreachable($node);
    }

    private function handleReachable(Node $node): void
    {
        if ($node->unreachable_notified_at !== null) {
            $claimed = Node::query()
                ->whereKey($node->getKey())
                ->whereNotNull('unreachable_notified_at')
                ->update(['unreachable_since' => null, 'unreachable_notified_at' => null]);

            if ($claimed === 1) {
                $node->team?->notify(new Reachable($node));
            }

            return;
        }

        if ($node->unreachable_since !== null) {
            Node::query()->whereKey($node->getKey())->update(['unreachable_since' => null]);
        }
    }

    private function handleUnreachable(Node $node): void
    {
        if ($node->unreachable_notified_at !== null) {
            return;
        }

        if ($node->unreachable_since === null) {
            Node::query()
                ->whereKey($node->getKey())
                ->whereNull('unreachable_since')
                ->update(['unreachable_since' => now()]);

            return;
        }

        if (Carbon::parse($node->unreachable_since)->isAfter(now()->subSeconds(self::GRACE_PERIOD_SECONDS))) {
            return;
        }

        $claimed = Node::query()
            ->whereKey($node->getKey())
            ->whereNull('unreachable_notified_at')
            ->update(['unreachable_notified_at' => now()]);

        if ($claimed === 1) {
            $node->team?->notify(new Unreachable($node));
        }
    }
}
