<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Stores the host that created the execution, so a host can count only the runs of its own workers.
 * The host name is internal, so API and MCP responses do not show it.
 */
trait RecordsWorkerHost
{
    protected static function bootRecordsWorkerHost(): void
    {
        static::creating(function (self $execution): void {
            $execution->worker_host ??= gethostname();
        });
    }

    protected function initializeRecordsWorkerHost(): void
    {
        $this->hidden[] = 'worker_host';
    }

    /**
     * Rows without a host were created before the column existed, so they can belong to this host.
     */
    public function scopeOnThisHost(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('worker_host', gethostname())->orWhereNull('worker_host'));
    }
}
