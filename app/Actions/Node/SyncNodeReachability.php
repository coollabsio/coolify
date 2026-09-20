<?php

namespace App\Actions\Node;

use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncNodeReachability
{
    use AsAction;

    public function handle(Node $node): bool
    {
        $isReachable = $node->hasRecentFluxHeartbeat();

        if ($node->is_reachable !== $isReachable) {
            $node->update(['is_reachable' => $isReachable]);
        }

        if (! $isReachable) {
            $connection = Cache::get($node->cacheKey());
            if (is_array($connection) && data_get($connection, 'status') === 'connected') {
                Cache::put($node->cacheKey(), [
                    ...$connection,
                    'status' => 'unavailable',
                ], now()->addMinutes(5));
            }
        }

        return $isReachable;
    }
}
