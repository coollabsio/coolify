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

    /**
     * Rules for the editable fields of an ignore. A vulnerability ID or a package is required.
     *
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        return [
            'vulnerability_id' => ['nullable', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', 'required_without:package'],
            'package' => ['nullable', 'string', 'max:214', 'required_without:vulnerability_id'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date'],
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
