<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $human_name
 * @property string|null $description
 * @property string|null $ports
 * @property string|null $exposes
 * @property string $status
 * @property int $service_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $exclude_from_status
 * @property string|null $image
 * @property int|null $public_port
 * @property bool $is_public
 * @property bool $is_log_drain_enabled
 * @property bool $is_include_timestamps
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property bool $is_gzip_enabled
 * @property bool $is_stripprefix_enabled
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LocalFileVolume> $fileStorages
 * @property-read int|null $file_storages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LocalPersistentVolume> $persistentStorages
 * @property-read int|null $persistent_storages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ScheduledDatabaseBackup> $scheduledBackups
 * @property-read int|null $scheduled_backups_count
 * @property-read \App\Models\Service|null $service
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase query()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereExcludeFromStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereExposes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereHumanName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereIsGzipEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereIsStripprefixEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase wherePorts($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereServiceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceDatabase withoutTrashed()
 *
 * @mixin \Eloquent
 */
class ServiceDatabase extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
        });
    }

    public function restart()
    {
        $container_id = $this->name.'-'.$this->service->uuid;
        remote_process(["docker restart {$container_id}"], $this->service->server);
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
        $image = str($this->image)->before(':');
        if ($image->value() === 'postgres') {
            $image = 'postgresql';
        }

        return "standalone-$image";
    }

    public function getServiceDatabaseUrl()
    {
        $port = $this->public_port;
        $realIp = $this->service->server->ip;
        if ($this->service->server->isLocalhost() || isDev()) {
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
        return service_configuration_dir()."/{$this->service->uuid}";
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
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
}
