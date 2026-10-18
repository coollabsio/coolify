<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A visual-only link between two resources of one environment on the environment canvas.
 */
class ResourceLink extends BaseModel
{
    protected $fillable = [
        'environment_id',
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'label',
        'created_by',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
