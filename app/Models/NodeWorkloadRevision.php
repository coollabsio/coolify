<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NodeWorkloadRevision extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(NodeContainer::class);
    }
}
