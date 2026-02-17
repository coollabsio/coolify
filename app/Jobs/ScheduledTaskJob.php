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
    public $tries = 1;

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
     * Public so it is included in the queue payload and available in failed().
     */
    public ?int $executionId = null;

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
            ]);

            // Store execution ID for timeout handling (public so it survives serialization)
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

                    // Run the command with throwError=false and redirect stderr to stdout
                    // so we always capture all output even when the command fails.
                    // Append a unique exit code marker so we can determine success/failure.
                    // Disable SSH multiplexing to prevent race conditions when multiple tasks run concurrently
                    // See: https://github.com/coollabsio/coolify/issues/6736
                    $wrappedExec = '('.$exec.') 2>&1; echo "COOLIFY_EXIT_CODE:$?"';
                    $rawOutput = instant_remote_process([$wrappedExec], $this->server, false, false, $this->timeout, disableMultiplexing: true);

                    // Parse exit code and clean output
                    $exitCode = $this->parseExitCode($rawOutput);
                    $this->task_output = $this->stripExitCodeLine($rawOutput);

                    if ($exitCode !== 0) {
                        $failureMessage = $this->task_output ?: "Task failed with exit code {$exitCode} (no output)";

                        $this->task_log->update([
                            'status' => 'failed',
                            'message' => $failureMessage,
                        ]);

                        $this->team?->notify(new TaskFailed($this->task, $failureMessage));

                        return;
                    }

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
            // Build a meaningful error message that includes any captured output
            $errorMessage = $e->getMessage();
            if ($this->task_output) {
                $errorMessage = $this->task_output."\n\n".$errorMessage;
            }
            if (empty($errorMessage)) {
                $errorMessage = 'Task failed with unknown error (no output captured)';
            }

            if ($this->task_log) {
                $this->task_log->update([
                    'status' => 'failed',
                    'message' => $errorMessage,
                ]);
            }

            // Log the error to the scheduled-errors channel
            Log::channel('scheduled-errors')->error('ScheduledTask execution failed', [
                'job' => 'ScheduledTaskJob',
                'task_id' => $this->task->uuid,
                'task_name' => $this->task->name,
                'server' => $this->server?->name ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            // Notify team about the failure
            $this->team?->notify(new TaskFailed($this->task, $errorMessage));

            // Do not re-throw: the execution log already records the failure status
            // and message. Re-throwing would cause Laravel to invoke failed() in a
            // potentially separate process (e.g., on timeout) where the execution
            // record may not be reliably found, leading to missing logs.
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
     * Parse the exit code from the wrapped command output.
     * The output ends with "COOLIFY_EXIT_CODE:<code>" appended by the wrapper.
     */
    private function parseExitCode(?string $output): int
    {
        if ($output === null || $output === '') {
            return 1;
        }

        if (preg_match('/COOLIFY_EXIT_CODE:(\d+)\s*$/', $output, $matches)) {
            return (int) $matches[1];
        }

        // If we can't find the exit code marker, assume failure
        return 1;
    }

    /**
     * Strip the COOLIFY_EXIT_CODE marker line from the command output.
     */
    private function stripExitCodeLine(?string $output): ?string
    {
        if ($output === null || $output === '') {
            return $output;
        }

        $cleaned = preg_replace('/\nCOOLIFY_EXIT_CODE:\d+\s*$/', '', $output);
        // Handle case where the marker is the only output
        $cleaned = preg_replace('/^COOLIFY_EXIT_CODE:\d+\s*$/', '', $cleaned);

        $cleaned = trim($cleaned);

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Handle a job failure (e.g., timeout kills the worker process).
     * This runs in a fresh process when a timeout occurs.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ScheduledTask permanently failed', [
            'job' => 'ScheduledTaskJob',
            'task_id' => $this->task->uuid,
            'task_name' => $this->task->name,
            'server' => $this->server?->name ?? 'unknown',
            'error' => $exception?->getMessage(),
        ]);

        // Find the execution record to update
        $execution = null;

        if ($this->executionId) {
            $execution = ScheduledTaskExecution::find($this->executionId);
        }

        if (! $execution) {
            $execution = ScheduledTaskExecution::query()
                ->where('scheduled_task_id', $this->task->id)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($execution) {
            $errorMessage = $exception?->getMessage() ?? 'Unknown error';

            // Preserve any output that was already captured before the failure
            $message = $execution->message
                ? $execution->message."\n\n".$errorMessage
                : $errorMessage;

            $execution->update([
                'status' => 'failed',
                'message' => $message,
                'finished_at' => Carbon::now()->toImmutable(),
            ]);
        } else {
            // Create an execution record as a last resort so the failure is visible in the UI
            ScheduledTaskExecution::create([
                'scheduled_task_id' => $this->task->id,
                'status' => 'failed',
                'message' => $exception?->getMessage() ?? 'Unknown error (no execution record found)',
                'started_at' => Carbon::now(),
                'finished_at' => Carbon::now()->toImmutable(),
            ]);

            Log::channel('scheduled-errors')->warning('Created new execution record for failed task (original not found)', [
                'execution_id' => $this->executionId,
                'task_id' => $this->task->uuid,
            ]);
        }

        // Notify team about the failure
        $this->team?->notify(new TaskFailed($this->task, $exception?->getMessage() ?? 'Unknown error'));
    }
}
