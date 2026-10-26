<?php

namespace App\Models;

class ScheduledJobState extends BaseModel
{
    protected $fillable = [
        'schedule_key',
        'last_scheduled_for',
    ];

    protected function casts(): array
    {
        return [
            'last_scheduled_for' => 'immutable_datetime',
        ];
    }
}
