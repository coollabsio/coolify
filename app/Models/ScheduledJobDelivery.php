<?php

namespace App\Models;

class ScheduledJobDelivery extends BaseModel
{
    protected $fillable = [
        'uuid',
        'schedule_key',
        'scheduled_for',
        'job_type',
        'resource_id',
        'payload',
        'status',
        'claim_token',
        'enqueued_at',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'payload' => 'array',
            'enqueued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
        ];
    }
}
