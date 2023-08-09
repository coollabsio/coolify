<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StandalonePostgresql extends BaseModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'init_scripts' => 'array',
        'postgres_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'postgres-data-' . $database->uuid,
                'mount_path' => '/var/lib/postgresql/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true
            ]);
        });
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn($value) => $value === "" ? null : $value,
        );
    }

    // Normal Deployments

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn() => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }

    public function type(): string
    {
        return 'standalone-postgresql';
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function scheduled_database_backups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function runtime_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }
}
