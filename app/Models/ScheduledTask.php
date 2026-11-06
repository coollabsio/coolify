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
        'failure_count' => ['type' => 'integer', 'description' => 'The number of failed runs in a row.'],
        'failing_since' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'The date and time of the first failed run in the current series of failures.'],
        'auto_disabled_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'The date and time when Coolify disabled the task because it kept failing.'],
        'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the scheduled task was created.'],
        'updated_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the scheduled task was last updated.'],
    ],
)]
class ScheduledTask extends BaseModel
{
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'uuid',
        'enabled',
        'name',
        'command',
        'frequency',
        'container',
        'timeout',
        'team_id',
        'application_id',
        'service_id',
    ];

    /**
     * A task is disabled automatically when it has failed at least this many times in a row...
     */
    public const AUTO_DISABLE_MIN_FAILURES = 5;

    /**
     * ...and has not had a successful run for at least this many days. Both limits must be
     * reached, so a short outage does not disable a task that runs every minute.
     */
    public const AUTO_DISABLE_MIN_DAYS = 3;

    /**
     * A task that is enabled again starts with a clean failure series.
     */
    protected static function booted(): void
    {
        static::saving(function (self $task) {
            if ($task->isDirty('enabled') && $task->enabled) {
                $task->failure_count = 0;
                $task->failing_since = null;
                $task->auto_disabled_at = null;
            }
        });
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return static::where('team_id', $teamId)->orderBy('created_at', 'desc');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'timeout' => 'integer',
            'failure_count' => 'integer',
            'failing_since' => 'datetime',
            'auto_disabled_at' => 'datetime',
        ];
    }

    public function recordRunSuccess(): void
    {
        if ($this->failure_count > 0 || $this->failing_since !== null) {
            $this->failure_count = 0;
            $this->failing_since = null;
            $this->save();
        }
    }

    /**
     * Count a failed run and disable the task when it keeps failing.
     *
     * @return bool True when this failure disabled the task.
     */
    public function recordRunFailure(): bool
    {
        $this->refresh();
        $this->increment('failure_count', 1, ['failing_since' => $this->failing_since ?? now()]);

        if (! $this->enabled
            || $this->failure_count < self::AUTO_DISABLE_MIN_FAILURES
            || $this->failing_since->gt(now()->subDays(self::AUTO_DISABLE_MIN_DAYS))) {
            return false;
        }

        $this->enabled = false;
        $this->auto_disabled_at = now();
        $this->save();

        return true;
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

    public function server(): ?Server
    {
        if ($this->application) {
            return $this->application->destination?->server;
        }

        if ($this->service) {
            return $this->service->destination?->server;
        }

        return null;
    }
}
