<?php

namespace App\Traits;

use App\Jobs\ReleaseManagedDnsRecordsJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ManagedDnsRecordReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases a resource's managed DNS references after it is deleted (soft or force delete).
 * The cleanup runs in a queued job after commit so provider errors never block the deletion.
 */
trait ReleasesManagedDnsRecords
{
    public static function bootReleasesManagedDnsRecords(): void
    {
        static::deleted(function (Model $resource): void {
            try {
                if (! self::hasManagedDnsReferences($resource)) {
                    return;
                }

                ReleaseManagedDnsRecordsJob::dispatch($resource->getMorphClass(), $resource->getKey())->afterCommit();
            } catch (Throwable $e) {
                Log::warning('Could not queue managed DNS cleanup for a deleted resource.', [
                    'resource_type' => $resource->getMorphClass(),
                    'resource_id' => $resource->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private static function hasManagedDnsReferences(Model $resource): bool
    {
        $query = ManagedDnsRecordReference::query()->where(fn ($query) => $query
            ->where('resource_type', $resource->getMorphClass())
            ->where('resource_id', $resource->getKey()));

        if ($resource instanceof Application) {
            $query->orWhere(fn ($query) => $query
                ->where('resource_type', (new ApplicationPreview)->getMorphClass())
                ->whereIn('resource_id', ApplicationPreview::withTrashed()->where('application_id', $resource->getKey())->select('id')));
        }

        return $query->exists();
    }
}
