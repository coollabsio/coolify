<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Visus\Cuid2\Cuid2;

abstract class BaseModel extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {
            // Generate a UUID if one isn't set
            if (! $model->uuid) {
                $model->uuid = (string) new Cuid2;
            }
        });
    }

    public function sanitizedName(): Attribute
    {
        return new Attribute(
            get: fn () => sanitize_string($this->getRawOriginal('name')),
        );
    }

    public function image(): Attribute
    {
        return new Attribute(
            get: fn () => sanitize_string($this->getRawOriginal('image')),
        );
    }
}
