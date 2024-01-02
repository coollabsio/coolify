<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTaskExecution extends BaseModel
{
    protected $guarded = [];

    public function scheduledTask(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class);
    }
}
