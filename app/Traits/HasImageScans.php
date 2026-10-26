<?php

namespace App\Traits;

use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\ImageScanPolicy;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Vulnerability scans of the images a resource runs. The scans, the scan rules, and the ignores of the resource go
 * away with it.
 */
trait HasImageScans
{
    public static function bootHasImageScans(): void
    {
        static::deleting(function ($model): void {
            $model->imageScans()->delete();
            ImageScanPolicy::query()->where('scope_type', $model->getMorphClass())->where('scope_id', $model->getKey())->delete();
            ImageScanIgnore::query()->where('resource_type', $model->getMorphClass())->where('resource_id', $model->getKey())->delete();
        });
    }

    /**
     * @return MorphMany<ImageScan, $this>
     */
    public function imageScans(): MorphMany
    {
        return $this->morphMany(ImageScan::class, 'resource');
    }
}
