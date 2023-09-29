<?php

namespace App\Jobs;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class DockerCleanupJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1000;
    public ?string $dockerRootFilesystem = null;
    public ?int $usageBefore = null;

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->uuid))->dontRelease()];
    }

    public function uniqueId(): string
    {
        return $this->server->uuid;
    }
    public function __construct(public Server $server)
    {
    }
    public function handle(): void
    {
        $queuedCount = 0;
        $this->server->applications()->each(function ($application) use ($queuedCount) {
            $count = data_get($application->deployments(), 'count', 0);
            $queuedCount += $count;
        });
        if ($queuedCount > 0) {
            ray('DockerCleanupJob: ApplicationDeploymentQueue is not empty, skipping')->color('orange');
            return;
        }
        try {
            if (!$this->server->isFunctional()) {
                return;
            }
            if (isDev()) {
                $this->dockerRootFilesystem = "/";
            } else {
                $this->dockerRootFilesystem = instant_remote_process(
                    [
                        "stat --printf=%m $(docker info --format '{{json .DockerRootDir}}'' |sed 's/\"//g')"
                    ],
                    $this->server,
                    false
                );
            }
            if (!$this->dockerRootFilesystem) {
                return;
            }
            $this->usageBefore = $this->getFilesystemUsage();
            if ($this->usageBefore >= $this->server->settings->cleanup_after_percentage) {
                ray('Cleaning up ' . $this->server->name)->color('orange');
                instant_remote_process(['docker image prune -af'], $this->server);
                instant_remote_process(['docker container prune -f --filter "label=coolify.managed=true"'], $this->server);
                instant_remote_process(['docker builder prune -af'], $this->server);
                $usageAfter = $this->getFilesystemUsage();
                if ($usageAfter <  $this->usageBefore) {
                    ray('Saved ' . ($this->usageBefore - $usageAfter) . '% disk space on ' . $this->server->name)->color('orange');
                    send_internal_notification('DockerCleanupJob done: Saved ' . ($this->usageBefore - $usageAfter) . '% disk space on ' . $this->server->name);
                } else {
                    ray('DockerCleanupJob failed to save disk space on ' . $this->server->name)->color('orange');
                }
            } else {
                ray('No need to clean up ' . $this->server->name)->color('orange');
            }
        } catch (\Throwable $e) {
            send_internal_notification('DockerCleanupJob failed with: ' . $e->getMessage());
            ray($e->getMessage())->color('orange');
            throw $e;
        }
    }

    private function getFilesystemUsage()
    {
        return instant_remote_process(["df '{$this->dockerRootFilesystem}'| tail -1 | awk '{ print $5}' | sed 's/%//g'"], $this->server, false);
    }
}
