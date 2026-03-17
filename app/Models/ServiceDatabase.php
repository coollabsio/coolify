<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int|null $application_id
 * @property int|null $service_id
 * @property string|null $custom_type
 * @property string|null $image
 * @property string|null $name
 * @property int|null $public_port
 * @property string|null $status
 * @property-read \App\Models\Application|null $application
 * @property-read \App\Models\Service|null $service
 */
class ServiceDatabase extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

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
                $service->forceFill(['last_online_at' => now()]);
            }
        });
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ServiceDatabase::where(function ($q) use ($teamId) {
            $q->whereRelation('service.environment.project.team', 'id', $teamId)
                ->orWhereRelation('application.environment.project.team', 'id', $teamId);
        })->orderBy('name');
    }

    /**
     * Get query builder for service databases owned by current team.
     * If you need all service databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return ServiceDatabase::where(function ($q) {
            $teamId = currentTeam()->id;
            $q->whereRelation('service.environment.project.team', 'id', $teamId)
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
        if ($this->application_id) {
            $container_id = $this->name.'-'.$this->application->uuid;
            remote_process(["docker restart {$container_id}"], $this->application->destination->server);

            return;
        }

        $container_id = $this->name.'-'.$this->service->uuid;
        remote_process(["docker restart {$container_id}"], $this->service->server);
    }

    public function isRunning(): bool
    {
        return str($this->status)->contains('running');
    }

    public function isExited(): bool
    {
        return str($this->status)->contains('exited');
    }

    public function isLogDrainEnabled(): bool
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function isStripprefixEnabled(): bool
    {
        return data_get($this, 'is_stripprefix_enabled', true);
    }

    public function isGzipEnabled(): bool
    {
        return data_get($this, 'is_gzip_enabled', true);
    }

    public function type(): string
    {
        return 'service';
    }

    public function serviceType(): ?string
    {
        return null;
    }

    public function databaseType(): string
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
        $server = $this->application_id
            ? $this->application->destination->server
            : $this->service->server;

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
            return application_configuration_dir()."/{$this->application->uuid}";
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

    public function getFilesFromServer(bool $isInit = false): void
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function isBackupSolutionAvailable(): bool
    {
        return str($this->databaseType())->contains('mysql') ||
            str($this->databaseType())->contains('postgres') ||
            str($this->databaseType())->contains('postgis') ||
            str($this->databaseType())->contains('mariadb') ||
            str($this->databaseType())->contains('mongo') ||
            filled($this->custom_type);
    }
}
