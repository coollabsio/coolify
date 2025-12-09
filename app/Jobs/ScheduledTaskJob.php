<?php

namespace App\Jobs;

use App\Events\ScheduledTaskDone;
use App\Exceptions\NonReportableException;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Notifications\ScheduledTask\TaskFailed;
use App\Notifications\ScheduledTask\TaskSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScheduledTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 300;

    public Team $team;

    public ?Server $server = null;

    public ScheduledTask $task;

    public Application|Service $resource;

    public ?ScheduledTaskExecution $task_log = null;

    /**
     * Store execution ID to survive job serialization for timeout handling.
     */
    protected ?int $executionId = null;

    public string $task_status = 'failed';

    public ?string $task_output = null;

    public array $containers = [];

    public string $server_timezone;

    public function __construct($task)
    {
        $this->onQueue('high');

        $this->task = $task;
        if ($service = $task->service()->first()) {
            $this->resource = $service;
        } elseif ($application = $task->application()->first()) {
            $this->resource = $application;
        } else {
            throw new \RuntimeException('ScheduledTaskJob failed: No resource found.');
        }
        $this->team = Team::findOrFail($task->team_id);
        $this->server_timezone = $this->getServerTimezone();

        // Set timeout from task configuration
        $this->timeout = $this->task->timeout ?? 300;
    }

    private function getServerTimezone(): string
    {
        if ($this->resource instanceof Application) {
            return $this->resource->destination->server->settings->server_timezone;
        } elseif ($this->resource instanceof Service) {
            return $this->resource->server->settings->server_timezone;
        }

        return 'UTC';
    }

    public function handle(): void
    {
        $startTime = Carbon::now();

        try {
            $this->task_log = ScheduledTaskExecution::create([
                'scheduled_task_id' => $this->task->id,
                'started_at' => $startTime,
                'retry_count' => $this->attempts() - 1,
            ]);

            // Store execution ID for timeout handling
            $this->executionId = $this->task_log->id;

            $this->server = $this->resource->destination->server;

            if ($this->resource->type() === 'application') {
                $containers = getCurrentApplicationContainerStatus($this->server, $this->resource->id, 0);
                if ($containers->count() > 0) {
                    $containers->each(function ($container) {
                        $this->containers[] = str_replace('/', '', $container['Names']);
                    });
                }
            } elseif ($this->resource->type() === 'service') {
                $this->resource->applications()->get()->each(function ($application) {
                    if (str(data_get($application, 'status'))->contains('running')) {
                        $this->containers[] = data_get($application, 'name').'-'.data_get($this->resource, 'uuid');
                    }
                });
                $this->resource->databases()->get()->each(function ($database) {
                    if (str(data_get($database, 'status'))->contains('running')) {
                        $this->containers[] = data_get($database, 'name').'-'.data_get($this->resource, 'uuid');
                    }
                });
            }
            if (count($this->containers) == 0) {
                throw new \Exception('ScheduledTaskJob failed: No containers running.');
            }

            if (count($this->containers) > 1 && empty($this->task->container)) {
                throw new \Exception('ScheduledTaskJob failed: More than one container exists but no container name was provided.');
            }

            foreach ($this->containers as $containerName) {
                if (count($this->containers) == 1 || str_starts_with($containerName, $this->task->container.'-'.$this->resource->uuid)) {
                    $cmd = "sh -c '".str_replace("'", "'\''", $this->task->command)."'";
                    $exec = "docker exec {$containerName} {$cmd}";
                    // Disable SSH multiplexing to prevent race conditions when multiple tasks run concurrently
                    // See: https://github.com/coollabsio/coolify/issues/6736
                    $this->task_output = instant_remote_process([$exec], $this->server, true, false, $this->timeout, disableMultiplexing: true);
                    $this->task_log->update([
                        'status' => 'success',
                        'message' => $this->task_output,
                    ]);

                    $this->team?->notify(new TaskSuccess($this->task, $this->task_output));

                    return;
                }
            }

            // No valid container was found.
            throw new NonReportableException('ScheduledTaskJob failed: No valid container was found. Is the container name correct?');
        } catch (\Throwable $e) {
            if ($this->task_log) {
                $this->task_log->update([
                    'status' => 'failed',
                    'message' => $this->task_output ?? $e->getMessage(),
                ]);
            }

            // Log the error to the scheduled-errors channel
            Log::channel('scheduled-errors')->error('ScheduledTask execution failed', [
                'job' => 'ScheduledTaskJob',
                'task_id' => $this->task->uuid,
                'task_name' => $this->task->name,
                'server' => $this->server?->name ?? 'unknown',
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Only notify and throw on final failure

            // Re-throw to trigger Laravel's retry mechanism with backoff
            throw $e;
        } finally {
            ScheduledTaskDone::dispatch($this->team->id);
            if ($this->task_log) {
                $finishedAt = Carbon::now();
                $duration = round($startTime->floatDiffInSeconds($finishedAt), 2);

                $this->task_log->update([
                    'finished_at' => $finishedAt->toImmutable(),
                    'duration' => $duration,
                ]);
            }
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 60, 120]; // 30s, 60s, 120s between retries
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ScheduledTask permanently failed', [
            'job' => 'ScheduledTaskJob',
            'task_id' => $this->task->uuid,
            'task_name' => $this->task->name,
            'server' => $this->server?->name ?? 'unknown',
            'total_attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        // Reload execution log from database
        // When a job times out, failed() is called in a fresh process with the original
        // queue payload, so $executionId will be null. We need to query for the latest execution.
        $execution = null;

        // Try to find execution using stored ID first (works for non-timeout failures)
        if ($this->executionId) {
            $execution = ScheduledTaskExecution::find($this->executionId);
        }

        // If no stored ID or not found, query for the most recent execution log for this task
        if (! $execution) {
            $execution = ScheduledTaskExecution::query()
                ->where('scheduled_task_id', $this->task->id)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        // Last resort: check task_log property
        if (! $execution && $this->task_log) {
            $execution = $this->task_log;
        }

        if ($execution) {
            $errorMessage = 'Job permanently failed after '.$this->attempts().' attempts';
            if ($exception) {
                $errorMessage .= ': '.$exception->getMessage();
            }

            $execution->update([
                'status' => 'failed',
                'message' => $errorMessage,
                'error_details' => $exception?->getTraceAsString(),
                'finished_at' => Carbon::now()->toImmutable(),
            ]);
        } else {
            Log::channel('scheduled-errors')->warning('Could not find execution log to update', [
                'execution_id' => $this->executionId,
                'task_id' => $this->task->uuid,
            ]);
        }

        // Notify team about permanent failure
        $this->team?->notify(new TaskFailed($this->task, $exception?->getMessage() ?? 'Unknown error'));
    }
}
