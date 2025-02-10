<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class LocalPersistentVolume extends Model
{
    protected $guarded = [];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (blank($model->name)) {
                $model->name = $model->resource->uuid.'-'.str_replace('/', '-', $model->mount_path);
            }
        });
    }

    public function resource()
    {
        return $this->morphTo('resource');
    }

    public function application()
    {
        return $this->morphTo('resource');
    }

    public function service()
    {
        return $this->morphTo('resource');
    }

    public function database()
    {
        return $this->morphTo('resource');
    }

    public function standalone_postgresql()
    {
        return $this->morphTo('resource');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->trim()->value,
        );
    }

    protected function mountPath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->trim()->start('/')->value
        );
    }

    protected function hostPath(): Attribute
    {
        return Attribute::make(
            set: function (?string $value) {
                if ($value) {
                    return str($value)->trim()->start('/')->value;
                } else {
                    return $value;
                }
            }
        );
    }
}
