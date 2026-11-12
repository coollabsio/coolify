<?php

namespace App\Models;

use App\Enums\NodeVolumeCopyState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Data of a volume on a Node other than `NodeVolume::node_id` (decision 0007). */
class NodeVolumeCopy extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => NodeVolumeCopyState::class,
            'delete_after' => 'datetime',
        ];
    }

    /** A restore on the Node of the volume keeps the old data in a volume with its own name. */
    public function podmanName(): string
    {
        return $this->podman_name ?? NodeVolume::PODMAN_PREFIX.$this->volume->uuid;
    }

    /** Whether this copy is the live data of its volume, which is never deleted as a copy. */
    public function isLiveData(): bool
    {
        return $this->node_id === $this->volume->node_id && $this->podmanName() === $this->volume->podmanName();
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(NodeVolume::class, 'node_volume_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
