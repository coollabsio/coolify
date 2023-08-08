<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StandalonePostgresql extends BaseModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
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

    public function type()
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
