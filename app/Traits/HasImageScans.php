<?php

namespace App\Traits;

use App\Models\ImageScan;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Vulnerability scans of the images a resource runs. The scans go away with the resource.
 */
trait HasImageScans
{
    public static function bootHasImageScans(): void
    {
        static::deleting(function ($model): void {
            $model->imageScans()->delete();
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
