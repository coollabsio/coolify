<?php

namespace App\Models;

use App\Enums\NodeOperationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeOperation extends BaseModel
{
    use HasFactory;

    /**
     * Commands that Coolify runs periodically in the background. They are
     * hidden from activity lists unless they fail.
     *
     * `discovery.corrosion.endpoints.reconcile.v1` is no longer sent. It stays
     * here so that rows from older versions stay hidden.
     */
    public const BACKGROUND_COMMAND_TYPES = [
        'container.list.v1',
        'discovery.corrosion.endpoints.reconcile.v1',
        'discovery.corrosion.inspect.v1',
        'network.firewall.inspect.v1',
        'network.wireguard.inspect.v1',
        'system.info.v1',
        'system.ping.v1',
    ];

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

    public function scopeUserFacing(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNotIn('command_type', self::BACKGROUND_COMMAND_TYPES)
            ->orWhereIn('status', [NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT, NodeOperationStatus::UNCERTAIN]));
    }
}
