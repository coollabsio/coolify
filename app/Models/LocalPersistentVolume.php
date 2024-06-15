<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property-write string $name
 * @property-write string $mount_path
 * @property-write string|null $host_path
 * @property string|null $container_id
 * @property string|null $resource_type
 * @property int|null $resource_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $is_readonly
 * @property-read Model|\Eloquent $application
 * @property-read Model|\Eloquent $database
 * @property-read Model|\Eloquent $service
 * @property-read Model|\Eloquent $standalone_postgresql
 *
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume query()
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereContainerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereHostPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereIsReadonly($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereMountPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereResourceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|LocalPersistentVolume whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class LocalPersistentVolume extends Model
{
    protected $guarded = [];

    public function application()
    {
        return $this->morphTo('resource');
    }

    public function service()
    {
        return $this->morphTo('resource');
    }

    public function database()
    {
        return $this->morphTo('resource');
    }

    public function standalone_postgresql()
    {
        return $this->morphTo('resource');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::of($value)->trim()->value,
        );
    }

    protected function mountPath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::of($value)->trim()->start('/')->value
        );
    }

    protected function hostPath(): Attribute
    {
        return Attribute::make(
            set: function (?string $value) {
                if ($value) {
                    return Str::of($value)->trim()->start('/')->value;
                } else {
                    return $value;
                }
            }
        );
    }
}
