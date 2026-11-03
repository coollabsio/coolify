<?php

namespace App\Models;

use App\Notifications\ScheduledTask\TaskFailed;
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
        'use_shell' => ['type' => 'boolean', 'description' => 'Run the command with `sh -c` in the container. Set it to false for containers without a shell: the command is then split into arguments and run directly, without shell features such as variables, pipes, or redirects.'],
        'consecutive_failures' => ['type' => 'integer', 'description' => 'The number of failed runs since the last successful run.'],
        'failing_since' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'The date and time of the first failed run since the last successful run.'],
        'disabled_reason' => ['type' => 'string', 'nullable' => true, 'description' => 'Why Coolify disabled the task automatically. It is cleared when the task is enabled again.'],
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
        'use_shell',
        'team_id',
        'application_id',
        'service_id',
    ];

    /**
     * A task is disabled automatically only when it failed this many times in a row...
     */
    public const AUTO_DISABLE_AFTER_FAILURES = 10;

    /**
     * ...and its first failure is this old, so a short outage does not disable a task that runs often.
     */
    public const AUTO_DISABLE_AFTER_HOURS = 24;

    protected static function booted(): void
    {
        // A task that is enabled again starts a new failure streak, and its next failure notifies at once.
        static::saving(function (ScheduledTask $task) {
            if ($task->isDirty('enabled') && $task->enabled) {
                $task->consecutive_failures = 0;
                $task->failing_since = null;
                $task->disabled_reason = null;
            }
        });
        static::saved(function (ScheduledTask $task) {
            if ($task->wasChanged('enabled') && $task->enabled) {
                NotificationThrottle::release($task, TaskFailed::class);
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
            'use_shell' => 'boolean',
            'consecutive_failures' => 'integer',
            'failing_since' => 'datetime',
        ];
    }

    /**
     * Count a failed run. Disables the task when it failed too often for too long.
     *
     * @return bool True when this failure disabled the task.
     */
    public function recordFailure(): bool
    {
        $this->refresh();
        $this->consecutive_failures++;
        $this->failing_since ??= now();

        $disable = $this->enabled
            && $this->consecutive_failures >= self::AUTO_DISABLE_AFTER_FAILURES
            && $this->failing_since->lte(now()->subHours(self::AUTO_DISABLE_AFTER_HOURS));
        if ($disable) {
            $this->enabled = false;
            $this->disabled_reason = "Disabled automatically after {$this->consecutive_failures} failed runs in a row since {$this->failing_since->toDateTimeString()} UTC.";
        }
        $this->save();

        return $disable;
    }

    /**
     * End the failure streak after a successful run.
     *
     * @return bool True when the team was notified about the failure, so it must be told that the task works again.
     */
    public function recordSuccess(): bool
    {
        if ($this->consecutive_failures > 0 || $this->failing_since !== null) {
            $this->forceFill(['consecutive_failures' => 0, 'failing_since' => null])->save();
        }

        return NotificationThrottle::release($this, TaskFailed::class);
    }

    /**
     * The part of `docker exec <container> ...` that runs the command, quoted for the host shell.
     * Without a shell, the command is split into words like a POSIX shell does, but nothing is expanded,
     * so it also runs in containers that have no `sh` (distroless or scratch images).
     */
    public function containerCommand(): string
    {
        if ($this->use_shell ?? true) {
            return 'sh -c '.escapeshellarg($this->command);
        }

        $arguments = self::splitCommandArguments($this->command);
        if ($arguments === []) {
            throw new \InvalidArgumentException('The command is empty.');
        }

        return implode(' ', array_map(escapeshellarg(...), $arguments));
    }

    /**
     * @return list<string>
     */
    private static function splitCommandArguments(string $command): array
    {
        $arguments = [];
        $word = null;
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            $next = $command[$i + 1] ?? null;

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $word .= $char;
                }

                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                } elseif ($char === '\\' && in_array($next, ['"', '\\', '$', '`'], true)) {
                    $word .= $next;
                    $i++;
                } else {
                    $word .= $char;
                }

                continue;
            }

            if ($char === '\\' && $next === "\n") {
                $i++;

                continue;
            }

            if (ctype_space($char)) {
                if ($word !== null) {
                    $arguments[] = $word;
                    $word = null;
                }

                continue;
            }

            $word ??= '';
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '\\' && $next !== null) {
                $word .= $next;
                $i++;
            } else {
                $word .= $char;
            }
        }

        if ($quote !== null) {
            throw new \InvalidArgumentException('The command has a quote that is not closed.');
        }
        if ($word !== null) {
            $arguments[] = $word;
        }

        return $arguments;
    }

    public function url(): ?string
    {
        $resource = $this->application ?? $this->service;
        if (! $resource) {
            return null;
        }
        $type = $resource instanceof Application ? 'application' : 'service';

        return base_url().'/project/'.data_get($resource, 'environment.project.uuid').'/environment/'.data_get($resource, 'environment.uuid')."/{$type}/{$resource->uuid}/tasks/{$this->uuid}";
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
