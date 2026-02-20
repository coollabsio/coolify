<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApplicationDatabase extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($database) {
            $database->persistentStorages()->delete();
            $database->fileStorages()->delete();
            $database->scheduledBackups()->delete();
        });
        static::saving(function ($database) {
            if ($database->isDirty('status')) {
                $database->forceFill(['last_online_at' => now()]);
            }
        });
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ApplicationDatabase::whereRelation('application.environment.project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Get query builder for application databases owned by current team.
     * If you need all application databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return ApplicationDatabase::whereRelation('application.environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all application databases owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return ApplicationDatabase::ownedByCurrentTeam()->get();
        });
    }

    public function restart()
    {
        $container_id = $this->name.'-'.$this->application->uuid;
        remote_process(["docker restart {$container_id}"], $this->application->server);
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
        return 'application-database';
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

        return "standalone-{$finalImage}";
    }

    public function getApplicationDatabaseUrl()
    {
        $port = $this->public_port;
        $realIp = $this->application->server->ip;
        if ($this->application->server->isLocalhost() || isDev()) {
            $realIp = base_ip();
        }

        return "{$realIp}:{$port}";
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function workdir()
    {
        return application_configuration_dir()."/{$this->application->uuid}";
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function destination()
    {
        return $this->application->destination();
    }

    public function server()
    {
        return $this->application->server();
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
