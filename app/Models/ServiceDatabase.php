<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceDatabase extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
            $service->scheduledBackups()->delete();
        });
        static::saving(function ($service) {
            if ($service->isDirty('status')) {
                $service->forceFill(['last_online_at' => now()]);
            }
        });
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ServiceDatabase::where(function ($query) use ($teamId) {
            $query->whereRelation('service.environment.project.team', 'id', $teamId)
                ->orWhereRelation('application.environment.project.team', 'id', $teamId);
        })->orderBy('name');
    }

    /**
     * Get query builder for service databases owned by current team.
     * If you need all service databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return ServiceDatabase::where(function ($query) {
            $query->whereRelation('service.environment.project.team', 'id', currentTeam()->id)
                ->orWhereRelation('application.environment.project.team', 'id', currentTeam()->id);
        })->orderBy('name');
    }

    /**
     * Get all service databases owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return ServiceDatabase::ownedByCurrentTeam()->get();
        });
    }

    /**
     * Check if this database belongs to a dockercompose Application.
     */
    public function isComposeApplication(): bool
    {
        return ! is_null($this->application_id);
    }

    /**
     * Get the parent resource (Service or Application).
     */
    public function parentResource()
    {
        if ($this->isComposeApplication()) {
            return $this->application;
        }

        return $this->service;
    }

    /**
     * Get the server this database runs on.
     */
    public function getServer()
    {
        if ($this->isComposeApplication()) {
            return $this->application->destination->server;
        }

        return $this->service->server;
    }

    /**
     * Get the parent UUID (used for container naming).
     */
    public function getParentUuid(): string
    {
        if ($this->isComposeApplication()) {
            return $this->application->uuid;
        }

        return $this->service->uuid;
    }

    /**
     * Get the parent name (used for backup directory naming).
     */
    public function getParentName(): string
    {
        if ($this->isComposeApplication()) {
            return $this->application->name;
        }

        return $this->service->name;
    }

    public function restart()
    {
        $parentUuid = $this->getParentUuid();
        $container_id = $this->name.'-'.$parentUuid;
        remote_process(["docker restart {$container_id}"], $this->getServer());
    }

    public function isRunning()
    {
        return str($this->status)->contains('running');
    }

    public function isExited()
    {
        return str($this->status)->contains('exited');
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function isStripprefixEnabled()
    {
        return data_get($this, 'is_stripprefix_enabled', true);
    }

    public function isGzipEnabled()
    {
        return data_get($this, 'is_gzip_enabled', true);
    }

    public function type()
    {
        return 'service';
    }

    public function serviceType()
    {
        return null;
    }

    public function databaseType()
    {
        if (filled($this->custom_type)) {
            return 'standalone-'.$this->custom_type;
        }
        $image = str($this->image)->before(':');
        if ($image->contains('supabase/postgres')) {
            $finalImage = 'supabase/postgres';
        } elseif ($image->contains('timescale')) {
            $finalImage = 'postgresql';
        } elseif ($image->contains('pgvector')) {
            $finalImage = 'postgresql';
        } elseif ($image->contains('postgres') || $image->contains('postgis')) {
            $finalImage = 'postgresql';
        } else {
            $finalImage = $image;
        }

        return "standalone-$finalImage";
    }

    public function getServiceDatabaseUrl()
    {
        $port = $this->public_port;
        $server = $this->getServer();
        $realIp = $server->ip;
        if ($server->isLocalhost() || isDev()) {
            $realIp = base_ip();
        }

        return "{$realIp}:{$port}";
    }

    public function team()
    {
        if ($this->isComposeApplication()) {
            return data_get($this->application, 'environment.project.team');
        }

        return data_get($this, 'environment.project.team');
    }

    public function workdir()
    {
        if ($this->isComposeApplication()) {
            return application_configuration_dir()."/{$this->application->uuid}";
        }

        return service_configuration_dir()."/{$this->service->uuid}";
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function isBackupSolutionAvailable()
    {
        return str($this->databaseType())->contains('mysql') ||
            str($this->databaseType())->contains('postgres') ||
            str($this->databaseType())->contains('postgis') ||
            str($this->databaseType())->contains('mariadb') ||
            str($this->databaseType())->contains('mongo') ||
            filled($this->custom_type);
    }
}
