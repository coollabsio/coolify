<?php

namespace App\Models;

use App\Traits\ScopedByAccessGrants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Docker Compose file on a cluster (decision 0023): one workload for each Compose service. Its
 * services reach each other by their bare names in the namespace `dns_label`, and the cluster
 * firewall allows all traffic between them.
 */
class NodeStack extends BaseModel
{
    use HasFactory, ScopedByAccessGrants;

    /** Namespaces that Sentinel and Coolify use for other names. */
    public const RESERVED_LABELS = ['default', 'nodes', 'cluster'];

    protected $guarded = [];

    protected $hidden = ['variables'];

    protected function casts(): array
    {
        return [
            'compose_parsed' => 'array',
            // The generated SERVICE_* values, created once for the stack (passwords, users, URLs).
            'variables' => 'encrypted:array',
        ];
    }

    /** `<label>.coolify.internal`, the DNS search domain of every service of the stack. */
    public function searchDomain(): string
    {
        return $this->dns_label.'.coolify.internal';
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

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(NodeCluster::class, 'node_cluster_id');
    }

    public function workloads(): HasMany
    {
        return $this->hasMany(NodeWorkload::class)->orderBy('id');
    }

    public function link(): ?string
    {
        $project = $this->project()->value('uuid');
        $environment = $this->environment()->value('uuid');
        if ($project === null || $environment === null) {
            return null;
        }

        return route('project.cluster-stack.show', ['project_uuid' => $project, 'environment_uuid' => $environment, 'stack_uuid' => $this->uuid]);
    }
}
