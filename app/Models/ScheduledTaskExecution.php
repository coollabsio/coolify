<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property string|null $message
 * @property int $scheduled_task_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ScheduledTask|null $scheduledTask
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution query()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution whereScheduledTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledTaskExecution whereUuid($value)
 *
 * @mixin \Eloquent
 */
class ScheduledTaskExecution extends BaseModel
{
    protected $guarded = [];

    public function scheduledTask(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class);
    }
}
