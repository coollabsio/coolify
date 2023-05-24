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
        'metadata',
    ];

    public $casts = [
        'metadata' => SchemalessAttributes::class,
    ];
    public function scopeWithExtraAttributes(): Builder
    {
        return $this->metadata->modelScope();
    }
}
