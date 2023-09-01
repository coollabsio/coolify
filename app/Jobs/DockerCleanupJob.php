<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DockerCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 500;
    public ?string $dockerRootFilesystem = null;
    public ?int $usageBefore = null;
    public function __construct()
    {
    }
    public function handle(): void
    {
        try {
            ray()->showQueries()->color('orange');
            $servers = Server::all();
            foreach ($servers as $server) {
                if (
                    !$server->settings->is_reachable && !$server->settings->is_usable
                ) {
                    continue;
                }
                if (isDev()) {
                    $this->dockerRootFilesystem = "/";
                } else {
                    $this->dockerRootFilesystem = instant_remote_process(
                        [
                            "stat --printf=%m $(docker info --format '{{json .DockerRootDir}}'' |sed 's/\"//g')"
                        ],
                        $server,
                        false
                    );
                }
                if (!$this->dockerRootFilesystem) {
                    continue;
                }
                $this->usageBefore = $this->getFilesystemUsage($server);
                if ($this->usageBefore >= $server->settings->cleanup_after_percentage) {
                    ray('Cleaning up ' . $server->name)->color('orange');
                    instant_remote_process(['docker image prune -af'], $server);
                    instant_remote_process(['docker container prune -f --filter "label=coolify.managed=true"'], $server);
                    instant_remote_process(['docker builder prune -af'], $server);
                    $usageAfter = $this->getFilesystemUsage($server);
                    if ($usageAfter <  $this->usageBefore) {
                        ray('Saved ' . ($this->usageBefore - $usageAfter) . '% disk space on ' . $server->name)->color('orange');
                        send_internal_notification('DockerCleanupJob done: Saved ' . ($this->usageBefore - $usageAfter) . '% disk space on ' . $server->name);
                    } else {
                        ray('DockerCleanupJob failed to save disk space on ' . $server->name)->color('orange');
                    }
                } else {
                    ray('No need to clean up ' . $server->name)->color('orange');
                }
            }
        } catch (\Exception $e) {
            send_internal_notification('DockerCleanupJob failed with: ' . $e->getMessage());
            ray($e->getMessage())->color('orange');
            throw $e;
        }
    }

    private function getFilesystemUsage(Server $server)
    {
        return instant_remote_process(["df '{$this->dockerRootFilesystem}'| tail -1 | awk '{ print $5}' | sed 's/%//g'"], $server, false);
    }
}
