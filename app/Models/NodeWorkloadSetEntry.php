<?php

namespace App\Models;

use App\Enums\NodeContainerSlot;
use App\Enums\NodeWorkloadDesiredState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One workload of the desired set of a Node that Sentinel converges (decision 0026): the revision,
 * the container slot, and the operation that its status events update.
 */
class NodeWorkloadSetEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'desired_state' => NodeWorkloadDesiredState::class,
            'container_slot' => NodeContainerSlot::class,
            'replaces_slot' => NodeContainerSlot::class,
            'network_revision' => 'integer',
            'redeploy' => 'boolean',
            'status_at' => 'datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(NodeWorkloadRevision::class, 'node_workload_revision_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(NodeOperation::class, 'node_operation_id');
    }
}
