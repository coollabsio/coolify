<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasDatabaseHealthCheck;
use App\Traits\HasMetrics;
use App\Traits\HasSafeStringAttribute;
use App\Traits\HasSecretManager;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StandaloneSqlite extends BaseModel
{
    use Auditable, ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, HasSecretManager, SoftDeletes;

    public const DATA_DIRECTORY = '/var/lib/sqlite';

    public const DATABASES_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*(,[A-Za-z0-9][A-Za-z0-9._-]*)*$/';

    protected array $auditExclude = ['last_online_at'];

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'sqlite_databases',
        'is_log_drain_enabled',
        'is_include_timestamps',
        'status',
        'image',
        'limits_memory',
        'limits_memory_swap',
        'limits_memory_swappiness',
        'limits_memory_reservation',
        'limits_cpus',
        'limits_cpuset',
        'limits_cpu_shares',
        'started_at',
        'restart_count',
        'last_restart_at',
        'last_restart_type',
        'last_online_at',
        'custom_docker_run_options',
        'destination_type',
        'destination_id',
        'environment_id',
        'health_check_enabled',
        'health_check_interval',
        'health_check_timeout',
        'health_check_retries',
        'health_check_start_period',
    ];

    protected $appends = ['database_type', 'server_status'];

    protected $casts = [
        'health_check_enabled' => 'boolean',
        'health_check_interval' => 'integer',
        'health_check_timeout' => 'integer',
        'health_check_retries' => 'integer',
        'health_check_start_period' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'sqlite-data-'.$database->uuid,
                'mount_path' => self::DATA_DIRECTORY,
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
        static::forceDeleting(function ($database) {
            $database->persistentStorages()->delete();
            $database->scheduledBackups()->delete();
            $database->environment_variables()->delete();
            $database->tags()->detach();
        });
        static::saving(function ($database) {
            if ($database->isDirty('status')) {
                $database->last_online_at = now();
            }
        });
    }

    /**
     * Get query builder for SQLite databases owned by current team.
     * If you need all databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return StandaloneSqlite::whereRelation('environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all SQLite databases owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return StandaloneSqlite::ownedByCurrentTeam()->get();
        });
    }

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->destination->server->isFunctional();
            }
        );
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = $this->image.$this->sqlite_databases;
        $newConfigHash .= $this->healthCheckConfigurationHash();
        $newConfigHash .= json_encode($this->environment_variables()->get('value')->makeVisible('value')->sort());
        $newConfigHash = md5($newConfigHash);
        $oldConfigHash = data_get($this, 'config_hash');
        if ($oldConfigHash === null) {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
        if ($oldConfigHash === $newConfigHash) {
            return false;
        } else {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
    }

    public function isRunning()
    {
        return (bool) str($this->status)->contains('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->startsWith('exited');
    }

    public function workdir()
    {
        return database_configuration_dir()."/{$this->uuid}";
    }

    public function deleteConfigurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function deleteVolumes()
    {
        $persistentStorages = $this->persistentStorages()->get() ?? collect();
        if ($persistentStorages->count() === 0) {
            return;
        }
        $server = data_get($this, 'destination.server');
        foreach ($persistentStorages as $storage) {
            instant_remote_process(['docker volume rm -f '.escapeshellarg($storage->name)], $server, false);
        }
    }

    public function realStatus()
    {
        return $this->getRawOriginal('status');
    }

    public function status(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (str($value)->contains('(')) {
                    $status = str($value)->before('(')->trim()->value();
                    $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                } elseif (str($value)->contains(':')) {
                    $status = str($value)->before(':')->trim()->value();
                    $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                } else {
                    $status = $value;
                    $health = 'unhealthy';
                }

                return "$status:$health";
            },
            get: function ($value) {
                if (str($value)->contains('(')) {
                    $status = str($value)->before('(')->trim()->value();
                    $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                } elseif (str($value)->contains(':')) {
                    $status = str($value)->before(':')->trim()->value();
                    $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                } else {
                    $status = $value;
                    $health = 'unhealthy';
                }

                return "$status:$health";
            },
        );
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function project()
    {
        return data_get($this, 'environment.project');
    }

    public function sslCertificates()
    {
        return $this->morphMany(SslCertificate::class, 'resource');
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.database.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                'database_uuid' => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function databaseType(): Attribute
    {
        return new Attribute(
            get: fn () => $this->type(),
        );
    }

    public function type(): string
    {
        return 'standalone-sqlite';
    }

    /**
     * The database file names configured for this database, in their configured order.
     *
     * @return list<string>
     */
    public function databaseFiles(): array
    {
        return collect(explode(',', (string) $this->sqlite_databases))
            ->map(fn (string $file): string => trim($file))
            ->filter(fn (string $file): bool => $file !== '' && preg_match(self::DATABASES_PATTERN, $file) === 1)
            ->values()
            ->all();
    }

    /**
     * The absolute path of one of this database's own files: the given file, or the first file.
     *
     * @throws InvalidArgumentException when the file is not one of this database's files
     */
    public function databaseFilePath(?string $file = null): string
    {
        $files = $this->databaseFiles();
        $file ??= $files[0] ?? null;

        if ($file === null || ! in_array($file, $files, true)) {
            throw new InvalidArgumentException('The SQLite database file is not one of the files of this database.');
        }

        return self::DATA_DIRECTORY.'/'.$file;
    }

    /**
     * The file a backup is restored into by default: the file whose name appears in the
     * backup file name (Coolify names backups sqlite-backup-<file>-<timestamp>.gz), else the first file.
     */
    public function defaultRestoreFile(?string $backupName): ?string
    {
        $files = $this->databaseFiles();
        $backupName = basename((string) $backupName);

        $matches = array_filter($files, fn (string $file): bool => $backupName === $file
            || str_starts_with($backupName, $file.'.')
            || str_starts_with($backupName, "sqlite-backup-{$file}-"));
        usort($matches, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $matches[0] ?? $files[0] ?? null;
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

    public function environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function runtime_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    /**
     * Volumes of other resources that mount this database's data volume.
     */
    public function connectedVolumes()
    {
        return $this->hasMany(LocalPersistentVolume::class, 'standalone_sqlite_id');
    }

    /**
     * Docker Compose applications of the same team and server that declare this database's data volume
     * (or the given volume) as external. Coolify uses an external volume as written, so they mount it
     * without a storage entry.
     *
     * @return Collection<int, Application>
     */
    public function composeApplicationsUsingDataVolume(?string $volumeName = null): Collection
    {
        $serverId = $this->destination?->server_id;
        $teamId = $this->environment?->project?->team_id;
        if ($serverId === null || $teamId === null) {
            return collect();
        }

        $volumeNames = $volumeName !== null
            ? collect([$volumeName])
            : $this->persistentStorages()->whereNull('host_path')->pluck('name');

        return Application::query()
            ->where('build_pack', 'dockercompose')
            ->whereRelation('environment.project', 'team_id', $teamId)
            ->with('destination')
            ->get()
            ->filter(fn (Application $application) => $application->destination?->server_id === $serverId
                && $volumeNames->intersect(composeExternalVolumeDockerNames($application->docker_compose_raw))->isNotEmpty())
            ->values();
    }

    /**
     * Whether an application mounts this database's data volume.
     */
    public function hasConnectedApplications(): bool
    {
        return $this->connectedVolumes()->exists() || $this->composeApplicationsUsingDataVolume()->isNotEmpty();
    }

    /**
     * Names of the resources that mount this database's data volume.
     *
     * @return Collection<int, string>
     */
    public function connectedApplicationNames(): Collection
    {
        return $this->connectedVolumes()->with('resource')->get()
            ->map(fn (LocalPersistentVolume $volume) => $volume->resource?->name)
            ->toBase()
            ->merge($this->composeApplicationsUsingDataVolume()->pluck('name'))
            ->filter()
            ->unique()
            ->values();
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function isBackupSolutionAvailable()
    {
        return true;
    }
}
