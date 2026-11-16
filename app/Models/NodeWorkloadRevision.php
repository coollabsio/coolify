<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NodeWorkloadRevision extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['environment', 'secrets'];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'environment' => 'encrypted:array',
            'secrets' => 'encrypted:array',
        ];
    }

    /** Whether a secret file has an owner or a mode, which an older Sentinel ignores (decision 0008 amendment). */
    public function setsSecretFileOwner(): bool
    {
        return collect($this->secrets ?? [])->contains(fn (string|array $entry): bool => is_array($entry));
    }

    /** Whether Sentinel mounts the Podman socket of the server in the container of this revision. */
    public function mountsContainerSocket(): bool
    {
        return filled(data_get($this->configuration, 'container_socket_paths'));
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(NodeContainer::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(NodeOperation::class, 'node_workload_revision_id');
    }

    /** The build that made the image of this revision. Only a Git application has one. */
    public function build(): HasOne
    {
        return $this->hasOne(NodeBuild::class, 'node_workload_revision_id');
    }
}
