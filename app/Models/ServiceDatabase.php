<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        $teamId = currentTeam()->id;

        return ServiceDatabase::where(function ($query) use ($teamId) {
            $query->whereRelation('service.environment.project.team', 'id', $teamId)
                ->orWhereRelation('application.environment.project.team', 'id', $teamId);
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

    public function restart(): void
    {
        $parentUuid = $this->application ? $this->application->uuid : $this->service->uuid;
        $container_id = $this->name.'-'.$parentUuid;
        remote_process(["docker restart {$container_id}"], $this->server());
    }

    /**
     * Resolve the server this database container runs on.
     */
    public function server(): ?Server
    {
        if ($this->application_id) {
            return data_get($this->application, 'destination.server');
        }

        return data_get($this->service, 'destination.server');
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

    public function getServiceDatabaseUrl(): string
    {
        $port = $this->public_port;
        $server = $this->server();
        if (! $server) {
            return '';
        }
        $realIp = $server->ip;
        if ($server->isLocalhost() || isDev()) {
            $realIp = base_ip();
        }

        return "{$realIp}:{$port}";
    }

    public function team()
    {
        if ($this->application_id) {
            return data_get($this, 'application.environment.project.team');
        }

        return data_get($this, 'service.environment.project.team');
    }

    public function workdir(): string
    {
        if ($this->application_id) {
            return base_configuration_dir().'/applications/'.$this->application->uuid;
        }

        return service_configuration_dir()."/{$this->service->uuid}";
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function application(): BelongsTo
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
