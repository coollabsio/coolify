<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Laravel\Horizon\Contracts\Silenced;

/**
 * Asynchronously connects the coolify-proxy to Docker networks.
 *
 * This job is dispatched from PushServerUpdateJob when the proxy is found running
 * to ensure it's connected to all required networks without blocking the status update.
 */
class ConnectProxyToNetworksJob implements ShouldBeEncrypted, ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public function middleware(): array
    {
        // Prevent overlapping executions for the same server and throttle to max once per 10 seconds
        return [
            (new WithoutOverlapping('connect-proxy-networks-'.$this->server->uuid))
                ->expireAfter(60)
                ->dontRelease(),
        ];
    }

    public function __construct(public Server $server) {}

    public function handle()
    {
        if (! $this->server->isFunctional()) {
            return;
        }

        $connectProxyToDockerNetworks = connectProxyToNetworks($this->server);

        if (empty($connectProxyToDockerNetworks)) {
            return;
        }

        instant_remote_process($connectProxyToDockerNetworks, $this->server, false);
    }
}
