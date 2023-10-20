<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StandaloneMongodb extends BaseModel
{
    use HasFactory;
    protected $guarded = [];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'mongodb-data-' . $database->uuid,
                'mount_path' => '/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true
            ]);
        });
        static::deleting(function ($database) {
            $database->scheduledBackups()->delete();
            $storages = $database->persistentStorages()->get();
            foreach ($storages as $storage) {
                instant_remote_process(["docker volume rm -f $storage->name"], $database->destination->server, false);
            }
            $database->persistentStorages()->delete();
            $database->environment_variables()->delete();
        });
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === "" ? null : $value,
        );
    }

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }

    public function type(): string
    {
        return 'standalone-mongodb';
    }
    public function getDbUrl() {
        if ($this->is_public) {
            return "mongodb://{$this->mongo_initdb_root_username}:{$this->mongo_initdb_root_password}@{$this->destination->server->getIp}:{$this->public_port}/?directConnection=true";
        } else {
            return "mongodb://{$this->mongo_initdb_root_username}:{$this->mongo_initdb_root_password}@{$this->uuid}:27017/?directConnection=true";
        }
    }
    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
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

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }
}
