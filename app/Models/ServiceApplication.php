<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $human_name
 * @property string|null $description
 * @property string|null $fqdn
 * @property string|null $ports
 * @property string|null $exposes
 * @property string $status
 * @property int $service_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $exclude_from_status
 * @property bool $required_fqdn
 * @property string|null $image
 * @property bool $is_log_drain_enabled
 * @property bool $is_include_timestamps
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property bool $is_gzip_enabled
 * @property bool $is_stripprefix_enabled
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LocalFileVolume> $fileStorages
 * @property-read int|null $file_storages_count
 * @property-read mixed $fqdns
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LocalPersistentVolume> $persistentStorages
 * @property-read int|null $persistent_storages_count
 * @property-read \App\Models\Service|null $service
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication query()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereExcludeFromStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereExposes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereFqdn($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereHumanName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereIsGzipEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereIsStripprefixEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication wherePorts($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereRequiredFqdn($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereServiceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|ServiceApplication withoutTrashed()
 *
 * @mixin \Eloquent
 */
class ServiceApplication extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->update(['fqdn' => null]);
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
        });
    }

    public function restart()
    {
        $container_id = $this->name.'-'.$this->service->uuid;
        instant_remote_process(["docker restart {$container_id}"], $this->service->server);
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

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function workdir()
    {
        return service_configuration_dir()."/{$this->service->uuid}";
    }

    public function serviceType()
    {
        $found = str(collect(SPECIFIC_SERVICES)->filter(function ($service) {
            return str($this->image)->before(':')->value() === $service;
        })->first());
        if ($found->isNotEmpty()) {
            return $found;
        }

        return null;
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

    public function fqdns(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->fqdn)
                ? []
                : explode(',', $this->fqdn),
        );
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }
}
