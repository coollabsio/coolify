<?php

namespace App\Jobs;

use App\Models\Server;
use Exception;
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

    public $timeout = 300;
    public ?string $dockerRootFilesystem = null;
    public ?int $usageBefore = null;

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->uuid))];
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
        $isInprogress = false;
        $this->server->applications()->each(function ($application) use (&$isInprogress) {
            if ($application->isDeploymentInprogress()) {
                $isInprogress = true;
                return;
            }
        });
        if ($isInprogress) {
            throw new Exception('DockerCleanupJob: ApplicationDeploymentQueue is not empty, skipping...');
        }
        try {
            if (!$this->server->isFunctional()) {
                return;
            }
            $this->dockerRootFilesystem = "/";
            $this->usageBefore = $this->getFilesystemUsage();
            if ($this->usageBefore >= $this->server->settings->cleanup_after_percentage) {
                ray('Cleaning up ' . $this->server->name);
                instant_remote_process(['docker image prune -af'], $this->server);
                instant_remote_process(['docker container prune -f --filter "label=coolify.managed=true"'], $this->server);
                instant_remote_process(['docker builder prune -af'], $this->server);
                $usageAfter = $this->getFilesystemUsage();
                if ($usageAfter <  $this->usageBefore) {
                    ray('Saved ' . ($this->usageBefore - $usageAfter) . '% disk space on ' . $this->server->name);
                    send_internal_notification('DockerCleanupJob done: Saved ' . ($this->usageBefore - $usageAfter) . '% disk space on ' . $this->server->name);
                } else {
                    ray('DockerCleanupJob failed to save disk space on ' . $this->server->name);
                }
            } else {
                ray('No need to clean up ' . $this->server->name);
            }
        } catch (\Throwable $e) {
            send_internal_notification('DockerCleanupJob failed with: ' . $e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }

    private function getFilesystemUsage()
    {
        return instant_remote_process(["df '{$this->dockerRootFilesystem}'| tail -1 | awk '{ print $5}' | sed 's/%//g'"], $this->server, false);
    }
}
