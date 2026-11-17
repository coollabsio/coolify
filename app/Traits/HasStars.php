<?php

namespace App\Traits;

use App\Models\Star;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasStars
{
    public static function bootHasStars(): void
    {
        static::deleted(fn ($model) => $model->stars()->delete());
    }

    public function stars(): MorphMany
    {
        return $this->morphMany(Star::class, 'starrable');
    }
}
