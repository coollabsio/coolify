<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Yaml\Yaml;

class ServiceDatabase extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'service_id',
        'application_id',
        'application_preview_id',
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
            $ownerCount = collect([
                $service->service_id,
                $service->application_id,
                $service->application_preview_id,
            ])->filter(fn ($id) => filled($id))->count();

            if ($ownerCount !== 1) {
                throw new \InvalidArgumentException('ServiceDatabase must have exactly one owner.');
            }

            if ($service->isDirty('status')) {
                $service->last_online_at = now();
            }
        });
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ServiceDatabase::where(function ($query) use ($teamId) {
            $query->whereRelation('service.environment.project.team', 'id', $teamId)
                ->orWhereRelation('application.environment.project.team', 'id', $teamId)
                ->orWhereRelation('application_preview.application.environment.project.team', 'id', $teamId);
        })->orderBy('name');
    }

    public static function ownedByCurrentTeam()
    {
        $teamId = currentTeam()->id;

        return ServiceDatabase::where(function ($query) use ($teamId) {
            $query->whereRelation('service.environment.project.team', 'id', $teamId)
                ->orWhereRelation('application.environment.project.team', 'id', $teamId)
                ->orWhereRelation('application_preview.application.environment.project.team', 'id', $teamId);
        })->orderBy('name');
    }

    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return ServiceDatabase::ownedByCurrentTeam()->get();
        });
    }

    public function restart()
    {
        $container_id = resolveServiceDatabaseContainer($this);
        $server = $this->server;

        if ($container_id && $server) {
            remote_process(["docker restart {$container_id}"], $server);
        }
    }



    public function getServerAttribute(): ?Server
    {
        if ($this->service_id) {
            return data_get($this, 'service.server');
        }
        if ($this->application_id) {
            return data_get($this, 'application.destination.server');
        }
        if ($this->application_preview_id) {
            return data_get($this, 'application_preview.application.destination.server');
        }

        return null;


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

    public function getServiceDatabaseUrl(): ?string
    {
        $port = $this->public_port;
        $server = null;
        if ($this->service_id) {
            $server = $this->service->server;
        } elseif ($this->application_id) {
            $server = $this->application->destination->server;
        } elseif ($this->application_preview_id) {
            $server = $this->application_preview->application->destination->server;
        }

        if (! $server) {
            return null;
        }

        $realIp = $server->ip;
        if ($server->isLocalhost() || isDev()) {
            $realIp = base_ip();
        }

        return "{$realIp}:{$port}";
    }

    public function team(): ?Team
    {
        if ($this->service_id) {
            return data_get($this, 'service.environment.project.team');
        } elseif ($this->application_id) {
            return data_get($this, 'application.environment.project.team');
        } elseif ($this->application_preview_id) {
            return data_get($this, 'application_preview.application.environment.project.team');
        }

        return null;
    }

    public function workdir(): ?string
    {
        if ($this->service_id) {
            return service_configuration_dir()."/{$this->service->uuid}";
        } elseif ($this->application_id) {
            return application_configuration_dir()."/{$this->application->uuid}";
        } elseif ($this->application_preview_id) {
            return application_configuration_dir()."/{$this->application_preview->application->uuid}";
        }

        return null;
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function application_preview(): BelongsTo
    {
        return $this->belongsTo(ApplicationPreview::class);
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
