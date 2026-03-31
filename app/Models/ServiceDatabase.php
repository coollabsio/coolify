<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ServiceDatabase extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'service_id',
        'application_id',
        'name',
        'human_name',
        'description',
        'fqdn',
        'ports',
        'exposes',
        'status',
        'exclude_from_status',
        'image',
        'public_port',
        'is_public',
        'is_log_drain_enabled',
        'is_include_timestamps',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'last_online_at',
        'is_migrated',
        'custom_type',
        'public_port_timeout',
    ];

    protected $casts = [
        'public_port_timeout' => 'integer',
    ];

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
            $service->scheduledBackups()->delete();
        });
        static::saving(function ($service) {
            if ($service->isDirty('status')) {
                $service->last_online_at = now();
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

    public function restart()
    {
        $container_id = $this->name.'-'.$this->service->uuid;
        remote_process(["docker restart {$container_id}"], $this->service->server);
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
        $server = $this->parentServer();
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
        return data_get($this, 'service.environment.project.team')
            ?? data_get($this, 'application.environment.project.team');
    }

    public function workdir()
    {
        if ($this->service) {
            return service_configuration_dir()."/{$this->service->uuid}";
        }
        if ($this->application) {
            return base_configuration_dir()."/applications/{$this->application->uuid}";
        }

        return service_configuration_dir();
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

    public function parentServer()
    {
        return data_get($this, 'service.destination.server')
            ?? data_get($this, 'application.destination.server');
    }

    public function parentDestination()
    {
        return data_get($this, 'service.destination')
            ?? data_get($this, 'application.destination');
    }

    public function parentEnvironment()
    {
        return data_get($this, 'service.environment')
            ?? data_get($this, 'application.environment');
    }

    public function parentProject()
    {
        return data_get($this, 'service.environment.project')
            ?? data_get($this, 'application.environment.project');
    }

    public function parentNetworkName(int $pullRequestId = 0): ?string
    {
        if ($this->service) {
            return $this->service->uuid;
        }
        if ($this->application) {
            return $pullRequestId !== 0
                ? "{$this->application->uuid}-{$pullRequestId}"
                : $this->application->uuid;
        }

        return null;
    }

    public function currentContainerName(int $pullRequestId = 0): ?string
    {
        if ($this->service) {
            return "{$this->name}-{$this->service->uuid}";
        }

        if (! $this->application) {
            return null;
        }

        $server = $this->parentServer();
        if (! $server) {
            return null;
        }

        $serviceSlug = Str::slug($this->name);
        $containers = getCurrentApplicationContainerStatus($server, $this->application->id, $pullRequestId, false)
            ->filter(function ($container) use ($serviceSlug) {
                $labels = data_get($container, 'Labels', '');

                return str($labels)->contains("coolify.serviceName={$serviceSlug}");
            });

        $runningContainer = $containers->first(function ($container) {
            $status = (string) (data_get($container, 'State') ?: data_get($container, 'Status', ''));

            return str($status)->lower()->contains('running') || str($status)->contains('Up');
        });

        return data_get($runningContainer ?: $containers->first(), 'Names');
    }

    public function backupDirectoryName(int $pullRequestId = 0): ?string
    {
        $containerName = $this->currentContainerName($pullRequestId);
        if (! $containerName) {
            return null;
        }

        $parentName = $this->service
            ? str($this->service->name)->slug()->value()
            : ($this->application ? str($this->application->name)->slug()->value() : str($this->name)->slug()->value());

        return $parentName.'-'.$containerName;
    }
}
