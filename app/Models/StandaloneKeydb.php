<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property mixed $keydb_password
 * @property string|null $keydb_conf
 * @property bool $is_log_drain_enabled
 * @property bool $is_include_timestamps
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string $status
 * @property string $image
 * @property bool $is_public
 * @property int|null $public_port
 * @property-write string|null $ports_mappings
 * @property string $limits_memory
 * @property string $limits_memory_swap
 * @property int $limits_memory_swappiness
 * @property string $limits_memory_reservation
 * @property string $limits_cpus
 * @property string|null $limits_cpuset
 * @property int $limits_cpu_shares
 * @property string|null $started_at
 * @property string $destination_type
 * @property int $destination_id
 * @property int|null $environment_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $config_hash
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $destination
 * @property-read \App\Models\Environment|null $environment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LocalFileVolume> $fileStorages
 * @property-read int|null $file_storages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LocalPersistentVolume> $persistentStorages
 * @property-read int|null $persistent_storages_count
 * @property-read mixed $ports_mappings_array
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EnvironmentVariable> $runtime_environment_variables
 * @property-read int|null $runtime_environment_variables_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ScheduledDatabaseBackup> $scheduledBackups
 * @property-read int|null $scheduled_backups_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tag> $tags
 * @property-read int|null $tags_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb query()
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereKeydbConf($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereKeydbPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneKeydb withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandaloneKeydb extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'keydb_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'keydb-data-'.$database->uuid,
                'mount_path' => '/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true,
            ]);
        });
        static::deleting(function ($database) {
            $database->scheduledBackups()->delete();
            $storages = $database->persistentStorages()->get();
            $server = data_get($database, 'destination.server');
            if ($server) {
                foreach ($storages as $storage) {
                    instant_remote_process(["docker volume rm -f $storage->name"], $server, false);
                }
            }
            $database->persistentStorages()->delete();
            $database->environment_variables()->delete();
            $database->tags()->detach();
        });
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = $this->image.$this->ports_mappings.$this->keydb_conf;
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

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.database.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_name' => data_get($this, 'environment.name'),
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

    public function type(): string
    {
        return 'standalone-keydb';
    }

    public function get_db_url(bool $useInternal = false): string
    {
        if ($this->is_public && ! $useInternal) {
            return "redis://{$this->keydb_password}@{$this->destination->server->getIp}:{$this->public_port}/0";
        } else {
            return "redis://{$this->keydb_password}@{$this->uuid}:6379/0";
        }
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

    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function runtime_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function getMetrics(int $mins = 5)
    {
        $server = $this->destination->server;
        $container_name = $this->uuid;
        if ($server->isMetricsEnabled()) {
            $from = now()->subMinutes($mins)->toIso8601ZuluString();
            $metrics = instant_remote_process(["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$server->settings->metrics_token}\" http://localhost:8888/api/container/{$container_name}/metrics/history?from=$from'"], $server, false);
            if (str($metrics)->contains('error')) {
                $error = json_decode($metrics, true);
                $error = data_get($error, 'error', 'Something is not okay, are you okay?');
                if ($error == 'Unauthorized') {
                    $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
                }
                throw new \Exception($error);
            }
            $metrics = str($metrics)->explode("\n")->skip(1)->all();
            $parsedCollection = collect($metrics)->flatMap(function ($item) {
                return collect(explode("\n", trim($item)))->map(function ($line) {
                    [$time, $cpu_usage_percent, $memory_usage, $memory_usage_percent] = explode(',', trim($line));
                    $cpu_usage_percent = number_format($cpu_usage_percent, 2);

                    return [(int) $time, (float) $cpu_usage_percent, (int) $memory_usage];
                });
            });

            return $parsedCollection->toArray();
        }
    }
}
