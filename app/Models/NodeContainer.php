<?php

namespace App\Models;

use App\Enums\NodeContainerManagementState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeContainer extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'ports' => 'array',
            'management_state' => NodeContainerManagementState::class,
            'is_managed' => 'boolean',
            'runtime_created_at' => 'datetime',
            'runtime_started_at' => 'datetime',
            'observed_at' => 'datetime',
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
}
