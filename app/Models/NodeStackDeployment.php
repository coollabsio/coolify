<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        ];
    }

    public function stack(): BelongsTo
    {
        return $this->belongsTo(NodeStack::class, 'node_stack_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
