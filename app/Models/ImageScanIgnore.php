<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A vulnerability or package a team chose to ignore, for one resource or (resource null) all team resources.
 */
class ImageScanIgnore extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'resource_type',
        'resource_id',
        'vulnerability_id',
        'package',
        'reason',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo('resource');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
