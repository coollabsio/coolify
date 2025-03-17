<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

abstract class BaseDatabaseModel extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = ['internal_db_url', 'external_db_url', 'database_type', 'server_status', 'href_link'];

    protected static function booted()
    {
        parent::booted();
        static::forceDeleting(function ($database) {
            $database->persistentStorages()->delete();
            $database->scheduledBackups()->delete();
            $database->environmentVariables()->delete();
            $database->tags()->detach();
        });
        static::saving(function ($database) {
            if ($database->isDirty('status')) {
                $database->forceFill(['last_online_at' => now()]);
            }
        });
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

    public function environmentVariables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->orderBy('key', 'asc');
    }

    public function runtimeEnvironmentVariables()
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

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
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
            get: fn () => blank($this->ports_mappings)
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

    public function project()
    {
        return data_get($this, 'environment.project');
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function workdir()
    {
        return database_configuration_dir()."/{$this->uuid}";
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

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->destination->server->isFunctional();
            }
        );
    }

    public function isRunning()
    {
        return (bool) str($this->status)->contains('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->startsWith('exited');
    }

    protected function getHrefLinkAttribute()
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

    public function deleteConfigurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function deleteVolumes(Collection $persistentStorages)
    {
        if ($persistentStorages->count() === 0) {
            return;
        }
        $server = data_get($this, 'destination.server');
        foreach ($persistentStorages as $storage) {
            instant_remote_process(["docker volume rm -f $storage->name"], $server, false);
        }
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
}
