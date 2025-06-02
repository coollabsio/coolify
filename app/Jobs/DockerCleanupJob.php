<?php

namespace App\Jobs;

use App\Actions\Server\CleanupDocker;
use App\Events\DockerCleanupDone;
use App\Models\DockerCleanupExecution;
use App\Models\Server;
use App\Notifications\Server\DockerCleanupFailed;
use App\Notifications\Server\DockerCleanupSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class DockerCleanupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public $tries = 1;

    public ?string $usageBefore = null;

    public ?DockerCleanupExecution $execution_log = null;

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->uuid))->dontRelease()];
    }

    public function __construct(public Server $server, public bool $manualCleanup = false) {}

    public function handle(): void
    {
        try {
            if (! $this->server->isFunctional()) {
                return;
            }

            $this->execution_log = DockerCleanupExecution::create([
                'server_id' => $this->server->id,
            ]);

            $this->usageBefore = $this->server->getDiskUsage();

            if ($this->manualCleanup || $this->server->settings->force_docker_cleanup) {
                $cleanup_log = CleanupDocker::run(server: $this->server);
                $usageAfter = $this->server->getDiskUsage();
                $message = ($this->manualCleanup ? 'Manual' : 'Forced').' Docker cleanup job executed successfully. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));

                return;
            }

            if (str($this->usageBefore)->isEmpty() || $this->usageBefore === null || $this->usageBefore === 0) {
                $cleanup_log = CleanupDocker::run(server: $this->server);
                $message = 'Docker cleanup job executed successfully, but no disk usage could be determined.';

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));
            }

            if ($this->usageBefore >= $this->server->settings->docker_cleanup_threshold) {
                $cleanup_log = CleanupDocker::run(server: $this->server);
                $usageAfter = $this->server->getDiskUsage();
                $diskSaved = $this->usageBefore - $usageAfter;

                if ($diskSaved > 0) {
                    $message = 'Saved '.$diskSaved.'% disk space. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';
                } else {
                    $message = 'Docker cleanup job executed successfully, but no disk space was saved. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';
                }

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));
            } else {
                $message = 'No cleanup needed for '.$this->server->name;

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));
            }
        } catch (\Throwable $e) {
            if ($this->execution_log) {
                $this->execution_log->update([
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ]);
                event(new DockerCleanupDone($this->execution_log));
            }
            $this->server->team?->notify(new DockerCleanupFailed($this->server, 'Docker cleanup job failed with the following error: '.$e->getMessage()));
            throw $e;
        } finally {
            if ($this->execution_log) {
                $this->execution_log->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            }
        }
    }
}
