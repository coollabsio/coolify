<?php

namespace App\Models;

use App\Enums\NodeBuildStatus;
use App\Enums\NodeWorkloadSourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One image build of a cluster application. It keeps a copy of the source
 * settings, so a later change of the application does not change a running
 * build. A revision exists only after the build succeeded.
 */
class NodeBuild extends BaseModel
{
    /** SelectBuilderNode throws this while every fitting builder Node is full; the build stays in the queue of its cluster. */
    public const BUILDERS_BUSY = 'Every builder server is busy.';

    /** Sentinel refuses a build at the limit of the Node and limits BuildKit (decision 0035). */
    public const CONCURRENT_CAPABILITY = 'image.build.concurrent.v1';

    /** The refusal of a Sentinel that already runs the limit of builds. The build goes back to the queue. */
    public const SENTINEL_BUSY = 'The builder is busy.';

    /** The highest value of `nodes.concurrent_builds`. */
    public const MAX_CONCURRENT_BUILDS = 16;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => NodeBuildStatus::class,
            'source_type' => NodeWorkloadSourceType::class,
            'skip_deploy' => 'boolean',
            'is_webhook' => 'boolean',
            'is_waiting' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'builder_queued_at' => 'datetime',
        ];
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(NodeCluster::class, 'node_cluster_id');
    }

    /** The builder Node. */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(NodeWorkloadRevision::class, 'node_workload_revision_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function logLines(): HasMany
    {
        return $this->hasMany(NodeBuildLogLine::class);
    }

    /** @param  Builder<NodeBuild>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', NodeBuildStatus::activeValues());
    }

    /**
     * The position of this build in the build queue of its cluster and the length of that queue,
     * or null when it does not wait for a builder. The position comes from the FIFO query.
     *
     * @return array{position: int, total: int}|null
     */
    public function builderQueuePosition(): ?array
    {
        if ($this->node_cluster_id === null || $this->builder_queued_at === null || $this->node_id !== null || $this->status !== NodeBuildStatus::QUEUED) {
            return null;
        }
        $queue = self::query()->builderQueue((int) $this->node_cluster_id);

        return ['position' => (clone $queue)->where('id', '<=', $this->id)->count(), 'total' => $queue->count()];
    }

    /**
     * The builds that wait for a free builder of the cluster, in FIFO order.
     *
     * @param  Builder<NodeBuild>  $query
     */
    public function scopeBuilderQueue(Builder $query, int $clusterId): void
    {
        $query->where('node_cluster_id', $clusterId)
            ->where('status', NodeBuildStatus::QUEUED->value)
            ->whereNull('node_id')
            ->whereNotNull('builder_queued_at')
            ->where('is_waiting', false)
            ->orderBy('id');
    }

    /** The branch and the build pack, or only the build pack of a build without Git. */
    public function sourceLabel(): string
    {
        return filled($this->git_branch) ? $this->git_branch.' · '.$this->source_type->label() : $this->source_type->label();
    }

    /** The name of the build container that Sentinel starts. */
    public function containerName(): string
    {
        return 'coolify-build-'.$this->uuid;
    }
}
