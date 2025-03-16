<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandaloneClickhouse extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = ['internal_db_url', 'external_db_url', 'database_type', 'server_status'];

    protected $casts = [
        'clickhouse_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'clickhouse-data-'.$database->uuid,
                'mount_path' => '/bitnami/clickhouse',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true,
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
                $database->forceFill(['last_online_at' => now()]);
            }
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
        $newConfigHash = $this->image.$this->ports_mappings;
        $newConfigHash .= json_encode($this->environment_variables()->get('value')->sort());
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

    public function delete_configurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function delete_volumes(Collection $persistentStorages)
    {
        if ($persistentStorages->count() === 0) {
            return;
        }
        $server = data_get($this, 'destination.server');
        foreach ($persistentStorages as $storage) {
            instant_remote_process(["docker volume rm -f $storage->name"], $server, false);
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

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === '' ? null : $value,
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
        return 'standalone-clickhouse';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: fn () => "clickhouse://{$this->clickhouse_admin_user}:{$this->clickhouse_admin_password}@{$this->uuid}:9000/{$this->clickhouse_db}",
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    return "clickhouse://{$this->clickhouse_admin_user}:{$this->clickhouse_admin_password}@{$this->destination->server->getIp}:{$this->public_port}/{$this->clickhouse_db}";
                }

                return null;
            }
        );
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
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->orderBy('key', 'asc');
    }

    public function runtime_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function getCpuMetrics(int $mins = 5)
    {
        $server = $this->destination->server;
        $container_name = $this->uuid;
        $from = now()->subMinutes($mins)->toIso8601ZuluString();
        $metrics = instant_remote_process(["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$server->settings->sentinel_token}\" http://localhost:8888/api/container/{$container_name}/cpu/history?from=$from'"], $server, false);
        if (str($metrics)->contains('error')) {
            $error = json_decode($metrics, true);
            $error = data_get($error, 'error', 'Something is not okay, are you okay?');
            if ($error === 'Unauthorized') {
                $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
            }
            throw new \Exception($error);
        }
        $metrics = json_decode($metrics, true);
        $parsedCollection = collect($metrics)->map(function ($metric) {
            return [(int) $metric['time'], (float) $metric['percent']];
        });

        return $parsedCollection->toArray();
    }

    public function getMemoryMetrics(int $mins = 5)
    {
        $server = $this->destination->server;
        $container_name = $this->uuid;
        $from = now()->subMinutes($mins)->toIso8601ZuluString();
        $metrics = instant_remote_process(["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$server->settings->sentinel_token}\" http://localhost:8888/api/container/{$container_name}/memory/history?from=$from'"], $server, false);
        if (str($metrics)->contains('error')) {
            $error = json_decode($metrics, true);
            $error = data_get($error, 'error', 'Something is not okay, are you okay?');
            if ($error === 'Unauthorized') {
                $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
            }
            throw new \Exception($error);
        }
        $metrics = json_decode($metrics, true);
        $parsedCollection = collect($metrics)->map(function ($metric) {
            return [(int) $metric['time'], (float) $metric['used']];
        });

        return $parsedCollection->toArray();
    }

    public function isBackupSolutionAvailable()
    {
        return false;
    }
}
