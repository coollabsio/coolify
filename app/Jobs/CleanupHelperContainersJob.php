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
            $containers = instant_remote_process_with_timeout(['docker container ps --format \'{{json .}}\' | jq -s \'map(select(.Image | contains("ghcr.io/coollabsio/coolify-helper")))\''], $this->server, false);
            $containerIds = collect(json_decode($containers))->pluck('ID');
            if ($containerIds->count() > 0) {
                foreach ($containerIds as $containerId) {
                    instant_remote_process_with_timeout(['docker container rm -f '.$containerId], $this->server, false);
                }
            }
        } catch (\Throwable $e) {
            send_internal_notification('CleanupHelperContainersJob failed with error: '.$e->getMessage());
        }
    }
}
