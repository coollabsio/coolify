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
    /** SelectBuilderNode throws this while every builder Node runs a build; the job waits. */
    public const BUILDERS_BUSY = 'Every builder server is busy.';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => NodeBuildStatus::class,
            'source_type' => NodeWorkloadSourceType::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
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

    /** The name of the build container that Sentinel starts. */
    public function containerName(): string
    {
        return 'coolify-build-'.$this->uuid;
    }
}
