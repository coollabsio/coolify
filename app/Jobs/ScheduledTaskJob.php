<?php

namespace App\Jobs;

use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Application;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ScheduledTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?Team $team = null;
    public Server $server;
    public ScheduledTask $task;
    public Application|Service $resource;

    public ?string $container_name = null;
    public ?string $directory_name = null;
    public ?ScheduledTaskExecution $backup_log = null;
    public string $task_status = 'failed';
    public int $size = 0;
    public ?string $backup_output = null;
    public ?S3Storage $s3 = null;

    public function __construct($task)
    {
        $this->task = $task;
        if ($service = $task->service()->first()) {
            $this->resource = $service;
        } else if ($application = $task->application()->first()) {
            $this->resource = $application;
        }
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->task->id)];
    }

    public function uniqueId(): int
    {
        return $this->task->id;
    }

    public function handle(): void
    {
        file_put_contents('/tmp/scheduled-job-run', 'ran in handle');
        try {
            echo($this->resource->type());
            file_put_contents('/tmp/scheduled-job-run-'.$this->task->id, $this->task->name);
        } catch (\Throwable $e) {
            send_internal_notification('ScheduledTaskJob failed with: ' . $e->getMessage());
            throw $e;
        } finally {
            // BackupCreated::dispatch($this->team->id);
        }
    }
    private function add_to_backup_output($output): void
    {
        if ($this->backup_output) {
            $this->backup_output = $this->backup_output . "\n" . $output;
        } else {
            $this->backup_output = $output;
        }
    }
}
