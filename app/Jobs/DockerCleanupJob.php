<?php

namespace App\Jobs;

use App\Actions\Server\CleanupDocker;
use App\Models\Server;
use App\Notifications\Server\DockerCleanupFailed;
use App\Notifications\Server\DockerCleanupSuccess;
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

            $this->usageBefore = $this->server->getDiskUsage();

            if ($this->manualCleanup || $this->server->settings->force_docker_cleanup) {
                CleanupDocker::run(server: $this->server);
                $usageAfter = $this->server->getDiskUsage();
                $this->server->team?->notify(new DockerCleanupSuccess($this->server, ($this->manualCleanup ? 'Manual' : 'Forced').' Docker cleanup job executed successfully. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.'));

                return;
            }

            if (str($this->usageBefore)->isEmpty() || $this->usageBefore === null || $this->usageBefore === 0) {
                CleanupDocker::run(server: $this->server);
                $this->server->team?->notify(new DockerCleanupSuccess($this->server, 'Docker cleanup job executed successfully, but no disk usage could be determined.'));
            }

            if ($this->usageBefore >= $this->server->settings->docker_cleanup_threshold) {
                CleanupDocker::run(server: $this->server);
                $usageAfter = $this->server->getDiskUsage();
                $diskSaved = $this->usageBefore - $usageAfter;

                if ($diskSaved > 0) {
                    $this->server->team?->notify(new DockerCleanupSuccess($this->server, 'Saved '.$diskSaved.'% disk space. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.'));
                } else {
                    $this->server->team?->notify(new DockerCleanupSuccess($this->server, 'Docker cleanup job executed successfully, but no disk space was saved. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.'));
                }
            } else {
                $this->server->team?->notify(new DockerCleanupSuccess($this->server, 'No cleanup needed for '.$this->server->name));
            }
        } catch (\Throwable $e) {
            $this->server->team?->notify(new DockerCleanupFailed($this->server, 'Docker cleanup job failed with the following error: '.$e->getMessage()));
            throw $e;
        }
    }
}
