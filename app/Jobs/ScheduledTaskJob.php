<?php

namespace App\Jobs;

use App\Events\ScheduledTaskDone;
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

class ScheduledTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Team $team;

    public Server $server;

    public ScheduledTask $task;

    public Application|Service $resource;

    public ?ScheduledTaskExecution $task_log = null;

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
        try {
            $this->task_log = ScheduledTaskExecution::create([
                'scheduled_task_id' => $this->task->id,
            ]);

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
                    $this->task_output = instant_remote_process([$exec], $this->server, true);
                    $this->task_log->update([
                        'status' => 'success',
                        'message' => $this->task_output,
                    ]);

                    $this->team?->notify(new TaskSuccess($this->task, $this->task_output));

                    return;
                }
            }

            // No valid container was found.
            throw new \Exception('ScheduledTaskJob failed: No valid container was found. Is the container name correct?');
        } catch (\Throwable $e) {
            if ($this->task_log) {
                $this->task_log->update([
                    'status' => 'failed',
                    'message' => $this->task_output ?? $e->getMessage(),
                ]);
            }
            $this->team?->notify(new TaskFailed($this->task, $e->getMessage()));
            throw $e;
        } finally {
            ScheduledTaskDone::dispatch($this->team->id);
            if ($this->task_log) {
                $this->task_log->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            }
        }
    }
}
