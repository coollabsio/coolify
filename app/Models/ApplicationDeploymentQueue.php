<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

class ApplicationDeploymentQueue extends Model
{
    protected $fillable = [
        'application_id',
        'status',
        'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => SchemalessAttributes::class,
    ];
    public function scopeWithExtraAttributes(): Builder
    {
        return $this->extra_attributes->modelScope();
    }
}
