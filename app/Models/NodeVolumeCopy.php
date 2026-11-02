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

    public function volume(): BelongsTo
    {
        return $this->belongsTo(NodeVolume::class, 'node_volume_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
