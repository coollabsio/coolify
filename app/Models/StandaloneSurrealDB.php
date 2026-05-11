<?php

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasMetrics;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandaloneSurrealDB extends BaseModel
{
    use ClearsGlobalSearchCache, HasFactory, HasMetrics, HasSafeStringAttribute, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'surrealdb_user',
        'surrealdb_password',
        'surrealdb_port',
        'is_log_drain_enabled',
        'is_include_timestamps',
        'status',
        'image',
        'is_public',
        'public_port',
        'ports_mappings',
        'limits_memory',
        'limits_memory_swap',
        'limits_memory_swappiness',
        'limits_memory_reservation',
        'limits_cpus',
        'limits_cpuset',
        'limits_cpu_shares',
        'started_at',
        'restart_count',
        'last_restart_at',
        'last_restart_type',
        'last_online_at',
        'public_port_timeout',
        'custom_docker_run_options',
        'destination_type',
        'destination_id',
        'environment_id',
    ];

    protected $appends = ['internal_db_url', 'external_db_url', 'server_status'];

    protected $casts = [
        'surrealdb_user' => 'encrypted',
        'surrealdb_password' => 'encrypted',
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'surrealdb-data-'.$database->uuid,
                'mount_path' => '/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
        static::forceDeleting(function ($database) {
            $database->persistentStorages()->delete();
            $database->scheduledBackups()->delete();
            $database->environment_variables()->delete();
            $database->tags()->detach();
        });
        static::saving(function ($database) {
            if ($database->isDirty('status')) {
                $database->last_online_at = now();
            }
        });
    }

    public function type(): string
    {
        return 'surrealdb';
    }

    public function internalDbUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => "http://{$this->uuid}:8000/rpc",
        );
    }

    public function externalDbUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    return "http://{$this->destination->server->ip}:{$this->public_port}/rpc";
                }

                return null;
            },
        );
    }
}
