<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeIngressRule extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['port' => 'integer'];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(NodeCluster::class, 'node_cluster_id');
    }

    public function destinationWorkload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'destination_workload_id');
    }
}
