<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S3 copies of a backup execution, one per destination. The `s3_uploaded` and `s3_storage_deleted` columns of the
 * execution summarize them.
 */
trait HasS3Replicas
{
    abstract public function s3Replicas(): HasMany;

    /**
     * Deletes the backup file from every destination that still has a copy.
     */
    public function deleteS3Copies(): void
    {
        $replicas = $this->s3Replicas()
            ->with('s3')
            ->where('s3_uploaded', true)
            ->where('s3_storage_deleted', false)
            ->get();

        try {
            foreach ($replicas as $replica) {
                if (! $replica->s3) {
                    throw new \RuntimeException('The S3 storage used by an existing backup is unavailable.');
                }
                if (filled($this->filename)) {
                    deleteBackupsS3($this->filename, $replica->s3);
                }
                $replica->update(['s3_storage_deleted' => true]);
            }
        } finally {
            $this->refreshS3Summary();
        }
    }

    public function hasLiveS3Copies(): bool
    {
        return $this->s3Replicas()->where('s3_uploaded', true)->where('s3_storage_deleted', false)->exists();
    }

    /**
     * Writes the replica states to the summary columns: uploaded when every copy uploaded, deleted when every uploaded
     * copy is deleted.
     */
    public function refreshS3Summary(): void
    {
        $replicas = $this->s3Replicas()->get();
        if ($replicas->isEmpty()) {
            return;
        }

        $uploaded = $replicas->where('s3_uploaded', true);

        $this->update([
            's3_uploaded' => $replicas->every(fn ($replica): bool => $replica->s3_uploaded === true),
            's3_storage_deleted' => $uploaded->isNotEmpty() && $uploaded->every(fn ($replica): bool => $replica->s3_storage_deleted),
        ]);
    }

    /**
     * Executions that have no S3 copy left to keep.
     */
    public function scopeWithoutLiveS3Copies(Builder $query): Builder
    {
        return $query->whereDoesntHave('s3Replicas', fn (Builder $query) => $query
            ->where('s3_uploaded', true)
            ->where('s3_storage_deleted', false));
    }
}
