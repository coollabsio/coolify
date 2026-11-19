<?php

namespace App\Jobs;

use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Releases the managed DNS records of hostnames that a live resource no longer uses after a domain edit
 * (API and other non-interactive paths). Records still used by another live resource stay; only owned records
 * are deleted. Provider errors and a busy hostname lock are retried a few times.
 */
class ReleaseRemovedDnsHostnamesJob implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 120;

    /**
     * @param  array<int, string>  $hostnames
     */
    public function __construct(
        public string $resourceType,
        public int|string $resourceId,
        public int $teamId,
        public array $hostnames,
    ) {}

    public function handle(ManagedDnsRecordCleanup $cleanup): void
    {
        $resource = $this->resource();
        if ($resource === null) {
            // A deleted resource releases all of its records through ReleaseManagedDnsRecordsJob.
            return;
        }

        $retry = false;
        foreach ($this->hostnames as $hostname) {
            if ($cleanup->releaseHostname($resource, $hostname, $this->teamId)?->shouldRetry()) {
                $retry = true;
            }
        }
        if (! $retry) {
            return;
        }

        if ($this->attempts() < self::MAX_ATTEMPTS) {
            $this->release(60 * $this->attempts());

            return;
        }

        Log::warning('Managed DNS records of removed hostnames could not be cleaned up; they were left in place.', [
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'hostnames' => $this->hostnames,
        ]);
    }

    private function resource(): ?Model
    {
        $resourceClass = Relation::getMorphedModel($this->resourceType) ?? $this->resourceType;
        if (! is_subclass_of($resourceClass, Model::class)) {
            return null;
        }

        return $resourceClass::query()->find($this->resourceId);
    }
}
