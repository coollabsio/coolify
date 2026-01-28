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
        $serviceOwned = ServiceDatabase::whereRelation('service.environment.project.team', 'id', $teamId);
        $applicationOwned = ServiceDatabase::whereRelation('application.environment.project.team', 'id', $teamId);

        return $serviceOwned->union($applicationOwned)->orderBy('name');
    }

    /**
     * Get query builder for service databases owned by current team.
     * If you need all service databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        $teamId = currentTeam()->id;
        $serviceOwned = ServiceDatabase::whereRelation('service.environment.project.team', 'id', $teamId);
        $applicationOwned = ServiceDatabase::whereRelation('application.environment.project.team', 'id', $teamId);

        return $serviceOwned->union($applicationOwned)->orderBy('name');
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
     * Check if this database belongs to a Docker Compose Application (not a Service).
     */
    public function isApplicationDatabase(): bool
    {
        return filled($this->application_id) && is_null($this->service_id);
    }

    public function restart()
    {
        if ($this->isApplicationDatabase()) {
            $container_id = $this->getContainerName();
            remote_process(["docker restart {$container_id}"], $this->application->destination->server);
        } else {
            $container_id = $this->name.'-'.$this->service->uuid;
            remote_process(["docker restart {$container_id}"], $this->service->server);
        }
    }

    /**
     * Get the container name for this database.
     * For Service databases: {name}-{serviceUuid}
     * For Application compose databases: {name}-{applicationUuid} (consistent naming)
     *   or resolved dynamically if the container has a timestamp suffix.
     */
    public function getContainerName(): string
    {
        if ($this->isApplicationDatabase()) {
            // Try to find the actual running container by matching name prefix.
            // Docker Compose apps may use timestamps in container names if
            // consistent naming is not enabled.
            $server = $this->getServer();
            if ($server) {
                try {
                    $prefix = $this->name.'-'.$this->application->uuid;
                    $result = instant_remote_process(
                        ["docker ps -f 'name={$prefix}' --format '{{.Names}}' | head -1"],
                        $server,
                        throwError: false
                    );
                    if (filled($result)) {
                        return trim($result);
                    }
                } catch (\Throwable) {
                    // Fall through to default
                }
            }

            return $this->name.'-'.$this->application->uuid;
        }

        return $this->name.'-'.$this->service->uuid;
    }

    /**
     * Get the server this database runs on.
     */
    public function getServer(): ?Server
    {
        if ($this->isApplicationDatabase()) {
            return $this->application->destination->server ?? null;
        }

        return $this->service->destination->server ?? null;
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
        if ($this->isApplicationDatabase()) {
            return 'application';
        }

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
        if (! $server) {
            return null;
        }
        $realIp = $server->ip;
        if ($server->isLocalhost() || isDev()) {
            $realIp = base_ip();
        }

        return "{$realIp}:{$port}";
    }

    public function team()
    {
        if ($this->isApplicationDatabase()) {
            return data_get($this, 'application.environment.project.team');
        }

        return data_get($this, 'service.environment.project.team');
    }

    public function workdir()
    {
        if ($this->isApplicationDatabase()) {
            return base_configuration_dir().'/applications/'.$this->application->uuid;
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
