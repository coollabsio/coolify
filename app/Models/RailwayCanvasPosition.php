<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted x/y position of a resource node on the Railway architecture canvas,
 * scoped to an environment. Extends the plain Model (not BaseModel) because this
 * is a lightweight layout table with no public UUID of its own.
 */
class RailwayCanvasPosition extends Model
{
    protected $fillable = [
        'environment_id',
        'resource_uuid',
        'x',
        'y',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
