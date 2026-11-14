<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A service of a stack that mounts a stack volume, at its path and read-write or read-only (decision 0024). */
class NodeVolumeMount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
        ];
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(NodeVolume::class, 'node_volume_id');
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }
}
