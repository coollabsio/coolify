<?php

namespace App\Jobs;

use App\Models\S3Storage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Re-tests S3 storages that failed validation, so a storage that becomes reachable again is usable without manual action.
 *
 * Each run stops after a time budget and saves the last tested id, so the next run continues from there
 * instead of re-testing the same slow storages from the start.
 */
class RevalidateUnusableS3StoragesJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 1800;

    /**
     * Stay well below $timeout, because a single unreachable endpoint can take a while to fail.
     */
    public const TIME_BUDGET_SECONDS = 1200;

    public const CURSOR_CACHE_KEY = 'revalidate-unusable-s3-storages:last-id';

    public function handle(): void
    {
        $deadline = now()->addSeconds(self::TIME_BUDGET_SECONDS);
        $lastId = Cache::get(self::CURSOR_CACHE_KEY);

        $completed = S3Storage::query()
            ->where('is_usable', false)
            ->when($lastId !== null, fn ($query) => $query->where('id', '>', $lastId))
            ->chunkById(100, function ($storages) use ($deadline): bool {
                foreach ($storages as $storage) {
                    if (now()->greaterThanOrEqualTo($deadline)) {
                        return false;
                    }

                    try {
                        $storage->testConnection(shouldSave: true);
                    } catch (\Throwable) {
                        // testConnection() keeps the storage unusable and notifies the team once.
                    }

                    Cache::forever(self::CURSOR_CACHE_KEY, $storage->id);
                }

                return true;
            });

        if ($completed) {
            Cache::forget(self::CURSOR_CACHE_KEY);
        }
    }
}
