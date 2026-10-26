<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Package list of one image on one server, shared by the resources that run it.
 */
class ImageSbom extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'image_id',
        'platform',
        'packages',
        'base_layer_ids',
        'packages_truncated',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'packages' => 'array',
            'base_layer_ids' => 'array',
            'packages_truncated' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(ImageScan::class);
    }
}
