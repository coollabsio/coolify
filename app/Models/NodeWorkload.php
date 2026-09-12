<?php

namespace App\Models;

use App\Enums\NodeWorkloadDesiredState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NodeWorkload extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['desired_state' => NodeWorkloadDesiredState::class];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(NodeWorkloadRevision::class);
    }

    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'node_workload_nodes')->withTimestamps();
    }

    public function containers(): HasMany
    {
        return $this->hasMany(NodeContainer::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(NodeOperation::class);
    }
}
