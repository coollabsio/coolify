<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Scheduled Task Execution model',
    type: 'object',
    properties: [
        'uuid' => ['type' => 'string', 'description' => 'The unique identifier of the execution.'],
        'status' => ['type' => 'string', 'enum' => ['success', 'failed', 'running'], 'description' => 'The status of the execution.'],
        'message' => ['type' => 'string', 'nullable' => true, 'description' => 'The output message of the execution.'],
        'retry_count' => ['type' => 'integer', 'description' => 'The number of retries.'],
        'duration' => ['type' => 'number', 'nullable' => true, 'description' => 'Duration in seconds.'],
        'started_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'When the execution started.'],
        'finished_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'When the execution finished.'],
        'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When the record was created.'],
        'updated_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When the record was last updated.'],
    ],
)]
class ScheduledTaskExecution extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'retry_count' => 'integer',
            'duration' => 'decimal:2',
        ];
    }

    public function scheduledTask(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class);
    }
}
