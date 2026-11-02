<?php

namespace App\Traits;

use App\Models\S3Storage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * S3 destinations of a backup schedule. The `s3_storage_id` column keeps the primary destination for older clients.
 */
trait HasS3Destinations
{
    abstract public function s3Storages(): BelongsToMany;

    /**
     * @return Collection<int, S3Storage> The primary destination comes first.
     */
    public function selectedS3Storages(): Collection
    {
        $storages = $this->relationLoaded('s3Storages')
            ? $this->s3Storages->sortBy('name')
            : $this->s3Storages()->orderBy('s3_storages.name')->get();

        if ($storages->isEmpty()) {
            return $this->s3 ? collect([$this->s3]) : collect();
        }

        return $storages
            ->sortBy(fn (S3Storage $storage): int => $storage->id === $this->s3_storage_id ? 0 : 1)
            ->values();
    }

    /**
     * Schedules that send backups to the given storage.
     */
    public function scopeUsingS3Storage(Builder $query, int $storageId): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('s3_storage_id', $storageId)
            ->orWhereHas('s3Storages', fn (Builder $query) => $query->whereKey($storageId)));
    }

    /**
     * Gives a cloned schedule the same destinations, limited to storages of the clone's team.
     */
    public function copyS3StoragesTo(self $copy): void
    {
        $copy->s3Storages()->sync(
            $this->s3Storages()->where('s3_storages.team_id', $copy->team_id)->pluck('s3_storages.id')->all(),
        );
    }

    /**
     * Replaces the selected destinations. The current primary destination stays primary when it is still selected.
     *
     * @param  array<int, int|string>  $storageIds
     */
    public function syncS3Storages(array $storageIds): void
    {
        $storageIds = collect($storageIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();

        $this->s3Storages()->sync($storageIds->all());
        $this->s3_storage_id = $storageIds->contains($this->s3_storage_id) ? $this->s3_storage_id : $storageIds->first();
        $this->save();
    }
}
