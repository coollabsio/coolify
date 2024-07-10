<?php

namespace App\Jobs;

use App\Actions\Server\CleanupDocker;
use App\Models\Server;
use App\Notifications\Server\DockerCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DockerCleanupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public ?int $usageBefore = null;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        try {
            $isInprogress = false;
            $this->server->applications()->each(function ($application) use (&$isInprogress) {
                if ($application->isDeploymentInprogress()) {
                    $isInprogress = true;

                    return;
                }
            });
            // if ($isInprogress) {
            //     throw new RuntimeException('DockerCleanupJob: ApplicationDeploymentQueue is not empty, skipping...');
            // }
            if (! $this->server->isFunctional()) {
                return;
            }
            $this->usageBefore = $this->server->getDiskUsage();
            ray('Usage before: '.$this->usageBefore);
            if ($this->usageBefore >= $this->server->settings->cleanup_after_percentage) {
                ray('Cleaning up '.$this->server->name);
                CleanupDocker::run($this->server);
                $usageAfter = $this->server->getDiskUsage();
                if ($usageAfter < $this->usageBefore) {
                    $this->server->team?->notify(new DockerCleanup($this->server, 'Saved '.($this->usageBefore - $usageAfter).'% disk space.'));
                    // ray('Saved ' . ($this->usageBefore - $usageAfter) . '% disk space on ' . $this->server->name);
                    // send_internal_notification('DockerCleanupJob done: Saved ' . ($this->usageBefore - $usageAfter) . '% disk space on ' . $this->server->name);
                    Log::info('DockerCleanupJob done: Saved '.($this->usageBefore - $usageAfter).'% disk space on '.$this->server->name);
                } else {
                    Log::info('DockerCleanupJob failed to save disk space on '.$this->server->name);
                }
            } else {
                ray('No need to clean up '.$this->server->name);
                Log::info('No need to clean up '.$this->server->name);
            }
        } catch (\Throwable $e) {
            // send_internal_notification('DockerCleanupJob failed with: '.$e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
