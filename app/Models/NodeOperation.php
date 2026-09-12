<?php

namespace App\Models;

use App\Enums\NodeOperationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeOperation extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => NodeOperationStatus::class,
            'attempt_count' => 'integer',
            'request' => 'array',
            'result' => 'array',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
