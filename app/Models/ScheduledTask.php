<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property bool $enabled
 * @property string $name
 * @property string $command
 * @property string $frequency
 * @property string|null $container
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $application_id
 * @property int|null $service_id
 * @property int $team_id
 * @property-read \App\Models\Application|null $application
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ScheduledTaskExecution> $executions
 * @property-read int|null $executions_count
 * @property-read \App\Models\ScheduledTaskExecution|null $latest_log
 * @property-read \App\Models\Service|null $service
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask query()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereCommand($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereContainer($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereFrequency($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereServiceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTask whereUuid($value)
 *
 * @mixin \Eloquent
 */
class ScheduledTask extends BaseModel
{
    protected $guarded = [];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function latest_log(): HasOne
    {
        return $this->hasOne(ScheduledTaskExecution::class)->latest();
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ScheduledTaskExecution::class);
    }
}
