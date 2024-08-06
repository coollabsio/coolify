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

class DockerCleanupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public int|string|null $usageBefore = null;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        try {
            if (! $this->server->isFunctional()) {
                return;
            }
            if ($this->server->settings->is_force_cleanup_enabled) {
                Log::info('DockerCleanupJob force cleanup on '.$this->server->name);
                CleanupDocker::run(server: $this->server, force: true);

                return;
            }

            $this->usageBefore = $this->server->getDiskUsage();
            if ($this->usageBefore === null) {
                Log::info('DockerCleanupJob force cleanup on '.$this->server->name);
                CleanupDocker::run(server: $this->server, force: true);

                return;
            }
            if ($this->usageBefore >= $this->server->settings->cleanup_after_percentage) {
                CleanupDocker::run(server: $this->server, force: false);
                $usageAfter = $this->server->getDiskUsage();
                if ($usageAfter < $this->usageBefore) {
                    $this->server->team?->notify(new DockerCleanup($this->server, 'Saved '.($this->usageBefore - $usageAfter).'% disk space.'));
                    Log::info('DockerCleanupJob done: Saved '.($this->usageBefore - $usageAfter).'% disk space on '.$this->server->name);
                } else {
                    Log::info('DockerCleanupJob failed to save disk space on '.$this->server->name);
                }
            } else {
                Log::info('No need to clean up '.$this->server->name);
            }
        } catch (\Throwable $e) {
            ray($e->getMessage());
            throw $e;
        }
    }
}
