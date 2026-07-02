<?php

namespace App\Models\V5;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

abstract class V5Model extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Model $model): void {
            if (
                Schema::hasColumn($model->getTable(), 'uuid')
                && ! $model->getAttribute('uuid')
            ) {
                $model->setAttribute('uuid', new_public_id());
            }
        });
    }
}
