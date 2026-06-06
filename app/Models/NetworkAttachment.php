<?php

namespace App\Models;

use App\Enums\NetworkAttachmentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NetworkAttachment extends BaseModel
{
    protected $fillable = [
        'server_id',
        'docker_network_id',
        'attachable_type',
        'attachable_id',
        'resource_type',
        'resource_id',
        'service_name',
        'container_name',
        'container_id',
        'aliases',
        'ipv4_address',
        'ipv6_address',
        'is_primary',
        'is_required',
        'is_managed',
        'is_runtime_discovered',
        'status',
        'last_checked_at',
        'last_error',
    ];

    protected $attributes = [
        'is_primary' => false,
        'is_required' => false,
        'is_managed' => false,
        'is_runtime_discovered' => false,
        'status' => 'unknown',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'is_primary' => 'boolean',
            'is_required' => 'boolean',
            'is_managed' => 'boolean',
            'is_runtime_discovered' => 'boolean',
            'status' => NetworkAttachmentStatus::class,
            'last_checked_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function dockerNetwork(): BelongsTo
    {
        return $this->belongsTo(DockerNetwork::class);
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
