<?php

namespace App\Jobs;

use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Releases the managed DNS references of a deleted resource and deletes records Coolify owns that are no longer used.
 * Never fails the deletion: provider errors are logged and retried a few times.
 */
class ReleaseManagedDnsRecordsJob implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 120;

    public function __construct(
        public string $resourceType,
        public int|string $resourceId,
    ) {}

    public function handle(ManagedDnsRecordCleanup $cleanup): void
    {
        if ($cleanup->releaseResource($this->resourceType, $this->resourceId)) {
            return;
        }

        if ($this->attempts() < self::MAX_ATTEMPTS) {
            $this->release(60 * $this->attempts());

            return;
        }

        Log::warning('Managed DNS records of a deleted resource could not be cleaned up; they were left in place.', [
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ]);
    }
}
