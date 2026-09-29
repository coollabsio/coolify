<?php

namespace App\Jobs;

use App\Actions\Node\UpgradeSentinel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Upgrades the team's Nodes one at a time and stops at the first failure. */
class UpgradeAllNodeSentinelsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 7200;

    public function __construct(public int $teamId, public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    public function handle(): void
    {
        $user = User::query()->findOrFail($this->userId);
        UpgradeSentinel::make()->upgradeAll($this->teamId, $user);
    }

    public function failed(?Throwable $exception): void
    {
        $key = UpgradeSentinel::upgradeAllCacheKey($this->teamId);
        $summary = Cache::get($key, []);
        Cache::put($key, [
            'upgraded' => [],
            ...$summary,
            'status' => 'failed',
            'error' => data_get($summary, 'error') ?? 'The Sentinel upgrade stopped before completion. Check the Sentinel version on each Node.',
            'finished_at' => now()->toIso8601String(),
        ], now()->addDay());
    }
}
