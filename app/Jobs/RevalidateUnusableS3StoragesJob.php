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

/**
 * Re-tests S3 storages that failed validation, so a storage that becomes reachable again is usable without manual action.
 */
class RevalidateUnusableS3StoragesJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 1800;

    public function handle(): void
    {
        S3Storage::query()
            ->where('is_usable', false)
            ->chunkById(100, function ($storages): void {
                foreach ($storages as $storage) {
                    try {
                        $storage->testConnection(shouldSave: true);
                    } catch (\Throwable) {
                        // testConnection() keeps the storage unusable and notifies the team once.
                    }
                }
            });
    }
}
