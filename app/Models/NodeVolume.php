<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A named Podman volume of a cluster application. It lives on exactly one Node: `node_id`
 * is the authority for the location of the data (decision 0007).
 */
class NodeVolume extends BaseModel
{
    public const PODMAN_PREFIX = 'coolify-vol-';

    /** At most this many volumes per application; Sentinel accepts at most 32 mounts. */
    public const MAX_PER_WORKLOAD = 32;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'detached_at' => 'datetime',
        ];
    }

    public function podmanName(): string
    {
        return self::PODMAN_PREFIX.$this->uuid;
    }

    public function isDetached(): bool
    {
        return $this->detached_at !== null;
    }

    /**
     * `not created` before the first deployment, `unknown` without a fresh inventory of its
     * Node, otherwise `ready` or `missing`.
     *
     * @param  list<string>|null  $inventory  Names from Node::freshVolumeInventory().
     */
    public function inventoryStatus(?array $inventory): string
    {
        return match (true) {
            $this->node_id === null => 'not created',
            $inventory === null => 'unknown',
            in_array($this->podmanName(), $inventory, true) => 'ready',
            default => 'missing',
        };
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }

    /** The owner of the volume under the name that v4 backup code uses for every backup target. */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function copies(): HasMany
    {
        return $this->hasMany(NodeVolumeCopy::class);
    }

    /** The v4 backup schedule of the volume (decision 0009). */
    public function scheduledBackups(): MorphMany
    {
        return $this->morphMany(ScheduledVolumeBackup::class, 'backupable');
    }
}
