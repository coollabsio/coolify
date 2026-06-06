<?php

namespace App\Models;

use App\Enums\DockerNetworkDriver;
use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkScope;
use App\Enums\DockerNetworkSourceType;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DockerNetwork extends BaseModel
{
    protected $fillable = [
        'server_id',
        'display_name',
        'docker_network_name',
        'driver',
        'scope',
        'subnet',
        'gateway',
        'ip_range',
        'aux_addresses',
        'internal',
        'attachable',
        'enable_ipv6',
        'labels',
        'options',
        'managed_by_coolify',
        'external',
        'is_system',
        'is_active',
        'source_type',
        'source_id',
        'network_role',
        'last_inspected_at',
        'last_inspect_data',
    ];

    protected $attributes = [
        'driver' => 'unknown',
        'scope' => 'unknown',
        'internal' => false,
        'attachable' => true,
        'enable_ipv6' => false,
        'managed_by_coolify' => false,
        'external' => true,
        'is_system' => false,
        'is_active' => true,
        'source_type' => 'unknown',
        'network_role' => 'unknown',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function (DockerNetwork $dockerNetwork) {
            if ($dockerNetwork->isDirty('docker_network_name')) {
                $dockerNetwork->docker_network_name = $dockerNetwork->getOriginal('docker_network_name');
            }

            if ($dockerNetwork->isDirty('server_id')) {
                $dockerNetwork->server_id = $dockerNetwork->getOriginal('server_id');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'driver' => DockerNetworkDriver::class,
            'scope' => DockerNetworkScope::class,
            'aux_addresses' => 'array',
            'internal' => 'boolean',
            'attachable' => 'boolean',
            'enable_ipv6' => 'boolean',
            'labels' => 'array',
            'options' => 'array',
            'managed_by_coolify' => 'boolean',
            'external' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'source_type' => DockerNetworkSourceType::class,
            'network_role' => DockerNetworkRole::class,
            'last_inspected_at' => 'datetime',
            'last_inspect_data' => 'array',
        ];
    }

    public function setDockerNetworkNameAttribute(string $value): void
    {
        if (! ValidationPatterns::isValidDockerNetwork($value)) {
            throw new \InvalidArgumentException('Invalid Docker network name. Must start with alphanumeric and contain only alphanumeric characters, dots, hyphens, and underscores.');
        }

        $this->attributes['docker_network_name'] = $value;
    }

    public function scopeByServer(Builder $query, Server|int $server): Builder
    {
        return $query->where('server_id', $server instanceof Server ? $server->id : $server);
    }

    public function scopeByName(Builder $query, string $name): Builder
    {
        return $query->where('docker_network_name', $name);
    }

    public function scopeByKey(Builder $query, int $id): Builder
    {
        return $query->whereKey($id);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function networkAttachments(): HasMany
    {
        return $this->hasMany(NetworkAttachment::class);
    }
}
