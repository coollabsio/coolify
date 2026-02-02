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
            // Service-based databases
            $query->whereHas('service.environment.project.team', function ($q) use ($teamId) {
                $q->where('id', $teamId);
            })
            // Application-based databases (Docker Compose via GitHub App)
            ->orWhereHas('application.environment.project.team', function ($q) use ($teamId) {
                $q->where('id', $teamId);
            });
        })->orderBy('name');
    }

    /**
     * Get query builder for service databases owned by current team.
     * Supports both Service-based and Application-based (Docker Compose via GitHub App) databases.
     * If you need all service databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        $teamId = currentTeam()->id;
        
        return ServiceDatabase::where(function ($query) use ($teamId) {
            // Service-based databases
            $query->whereHas('service.environment.project.team', function ($q) use ($teamId) {
                $q->where('id', $teamId);
            })
            // Application-based databases (Docker Compose via GitHub App)
            ->orWhereHas('application.environment.project.team', function ($q) use ($teamId) {
                $q->where('id', $teamId);
            });
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

    public function restart()
    {
        $container_id = $this->name.'-'.$this->getParentResourceUuid();
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

    /**
     * Get the team that owns this database.
     * Works for both Service-based and Application-based databases.
     */
    public function team()
    {
        if ($this->service_id) {
            return data_get($this, 'service.environment.project.team');
        }
        
        if ($this->application_id) {
            return data_get($this, 'application.environment.project.team');
        }
        
        return null;
    }

    /**
     * Get the server where this database runs.
     * Works for both Service-based and Application-based databases.
     */
    public function getServer()
    {
        if ($this->service_id && $this->service) {
            return $this->service->server;
        }
        
        if ($this->application_id && $this->application) {
            return $this->application->destination->server;
        }
        
        return null;
    }

    /**
     * Get the parent resource UUID (Service or Application).
     */
    public function getParentResourceUuid()
    {
        if ($this->service_id && $this->service) {
            return $this->service->uuid;
        }
        
        if ($this->application_id && $this->application) {
            return $this->application->uuid;
        }
        
        return null;
    }

    /**
     * Get the parent resource (Service or Application).
     */
    public function getParentResource()
    {
        if ($this->service_id) {
            return $this->service;
        }
        
        if ($this->application_id) {
            return $this->application;
        }
        
        return null;
    }

    /**
     * Check if this database belongs to an Application (Docker Compose via GitHub App).
     */
    public function isApplicationDatabase(): bool
    {
        return filled($this->application_id);
    }

    /**
     * Check if this database belongs to a Service (Empty Docker Compose or one-click).
     */
    public function isServiceDatabase(): bool
    {
        return filled($this->service_id);
    }

    public function workdir()
    {
        if ($this->service_id && $this->service) {
            return service_configuration_dir()."/{$this->service->uuid}";
        }
        
        if ($this->application_id && $this->application) {
            return application_configuration_dir()."/{$this->application->uuid}";
        }
        
        return null;
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Relationship to Application model.
     * Used for Docker Compose deployments via GitHub App.
     */
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
