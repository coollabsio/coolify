<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A TCP or UDP port of a workload that every Node of its cluster publishes (decision 0016). */
class NodePublishedPort extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'host_port' => 'integer',
            'container_port' => 'integer',
            'source_cidrs' => 'array',
        ];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(NodeCluster::class, 'node_cluster_id');
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }

    /** @return array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>} */
    public function spec(): array
    {
        return [
            'protocol' => $this->protocol,
            'host_port' => $this->host_port,
            'container_port' => $this->container_port,
            'source_cidrs' => array_values($this->source_cidrs ?? []),
        ];
    }
}
