<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One deploy of a stack, for its deployment history (decision 0023). */
class NodeStackDeployment extends BaseModel
{
    public const string DEPLOYING = 'deploying';

    public const string DEPLOYED = 'deployed';

    public const string FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'finished_at' => 'datetime',
            'is_push' => 'boolean',
        ];
    }

    public function stack(): BelongsTo
    {
        return $this->belongsTo(NodeStack::class, 'node_stack_id');
    }

    /** The builds of the services with `build:` that this deploy started or reused (decision 0035). */
    public function builds(): HasMany
    {
        return $this->hasMany(NodeBuild::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
