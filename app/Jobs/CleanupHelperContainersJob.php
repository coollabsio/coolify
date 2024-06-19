<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupHelperContainersJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        try {
            ray('Cleaning up helper containers on '.$this->server->name);
            $containers = instant_remote_process(['docker container ps --filter "ancestor=ghcr.io/coollabsio/coolify-helper:next" --filter "ancestor=ghcr.io/coollabsio/coolify-helper:latest" --format \'{{json .}}\''], $this->server, false);
            $containers = format_docker_command_output_to_json($containers);
            if ($containers->count() > 0) {
                foreach ($containers as $container) {
                    $containerId = data_get($container, 'ID');
                    ray('Removing container '.$containerId);
                    instant_remote_process(['docker container rm -f '.$containerId], $this->server, false);
                }
            }
        } catch (\Throwable $e) {
            send_internal_notification('CleanupHelperContainersJob failed with error: '.$e->getMessage());
            ray($e->getMessage());
        }
    }
}
