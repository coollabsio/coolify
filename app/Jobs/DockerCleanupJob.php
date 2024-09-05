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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DockerCleanupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public $tries = 1;

    public ?string $usageBefore = null;

    public function __construct(public Server $server) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->server->id)];
    }

    public function uniqueId(): int
    {
        return $this->server->id;
    }

    public function handle(): void
    {
        try {
            if (! $this->server->isFunctional()) {
                return;
            }
            if ($this->server->settings->force_docker_cleanup) {
                Log::info('DockerCleanupJob force cleanup on '.$this->server->name);
                CleanupDocker::run(server: $this->server);

                return;
            }

            $this->usageBefore = $this->server->getDiskUsage();
            if (str($this->usageBefore)->isEmpty() || $this->usageBefore === null || $this->usageBefore === 0) {
                Log::info('DockerCleanupJob force cleanup on '.$this->server->name);
                CleanupDocker::run(server: $this->server);

                return;
            }
            if ($this->usageBefore >= $this->server->settings->docker_cleanup_threshold) {
                CleanupDocker::run(server: $this->server);
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
            CleanupDocker::run(server: $this->server);
            Log::error('DockerCleanupJob failed: '.$e->getMessage());
            throw $e;
        }
    }
}
