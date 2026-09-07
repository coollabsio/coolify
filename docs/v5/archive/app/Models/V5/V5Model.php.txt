<?php

namespace App\Models\V5;

use Illuminate\Database\Eloquent\Model;

abstract class V5Model extends Model
{
    /**
     * Whether the model's table has a `uuid` column. Models without one (set
     * this to false there) skip public-id generation and route on the primary
     * key instead.
     */
    protected bool $hasUuidColumn = true;

    public function getRouteKeyName(): string
    {
        return $this->hasUuidColumn ? 'uuid' : $this->getKeyName();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if ($model->hasUuidColumn && ! $model->getAttribute('uuid')) {
                $model->setAttribute('uuid', $model->newUniquePublicId());
            }
        });
    }

    /**
     * Generate a public id, regenerating (up to three candidates) when one is
     * already taken. A concurrent insert between this exists() check and our
     * own insert can still collide; the unique index then rejects the insert,
     * which is an acceptable residual race for these cheap, retryable writes.
     */
    protected function newUniquePublicId(): string
    {
        $attempts = 0;

        do {
            $candidate = $this->newPublicIdCandidate();
            $attempts++;
        } while (
            $attempts < 3
            && $this->newModelQuery()->where('uuid', $candidate)->exists()
        );

        return $candidate;
    }

    protected function newPublicIdCandidate(): string
    {
        return new_public_id();
    }
}
