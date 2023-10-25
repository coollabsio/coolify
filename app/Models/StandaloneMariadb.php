<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StandaloneMariadb extends BaseModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'mariadb_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'mariadb-data-' . $database->uuid,
                'mount_path' => '/var/lib/mysql',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true
            ]);
        });
        static::deleting(function ($database) {
            $storages = $database->persistentStorages()->get();
            foreach ($storages as $storage) {
                instant_remote_process(["docker volume rm -f $storage->name"], $database->destination->server, false);
            }
            $database->scheduledBackups()->delete();
            $database->persistentStorages()->delete();
            $database->environment_variables()->delete();
        });
    }
    public function type(): string
    {
        return 'standalone-mariadb';
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

    public function getDbUrl(bool $useInternal = false): string
    {
        if ($this->is_public && !$useInternal) {
            return "mysql://{$this->mariadb_user}:{$this->mariadb_password}@{$this->destination->server->getIp}:{$this->public_port}/{$this->mariadb_database}";
        } else {
            return "mysql://{$this->mariadb_user}:{$this->mariadb_password}@{$this->uuid}:3306/{$this->mariadb_database}";
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
