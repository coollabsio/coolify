<?php

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Scheduled Task model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The unique identifier of the scheduled task in the database.'],
        'uuid' => ['type' => 'string', 'description' => 'The unique identifier of the scheduled task.'],
        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled.'],
        'name' => ['type' => 'string', 'description' => 'The name of the scheduled task.'],
        'command' => ['type' => 'string', 'description' => 'The command to execute.'],
        'frequency' => ['type' => 'string', 'description' => 'The frequency of the scheduled task.'],
        'container' => ['type' => 'string', 'nullable' => true, 'description' => 'The container where the command should be executed.'],
        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.'],
        'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the scheduled task was created.'],
        'updated_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the scheduled task was last updated.'],
    ],
)]
class ScheduledTask extends BaseModel
{
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'enabled',
        'name',
        'command',
        'frequency',
        'container',
        'timeout',
    ];

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return static::where('team_id', $teamId)->orderBy('created_at', 'desc');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'timeout' => 'integer',
        ];
    }

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
        // Last execution first
        return $this->hasMany(ScheduledTaskExecution::class)->orderBy('created_at', 'desc');
    }

    public function server()
    {
        if ($this->application) {
            if ($this->application->destination && $this->application->destination->server) {
                return $this->application->destination->server;
            }
        } elseif ($this->service) {
            if ($this->service->destination && $this->service->destination->server) {
                return $this->service->destination->server;
            }
        } elseif ($this->database) {
            if ($this->database->destination && $this->database->destination->server) {
                return $this->database->destination->server;
            }
        }

        return null;
    }
}
