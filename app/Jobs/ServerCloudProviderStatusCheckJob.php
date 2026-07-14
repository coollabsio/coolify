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
use Illuminate\Support\Facades\Log;

class ServerCloudProviderStatusCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function __construct(public Server $server)
    {
        $this->onQueue('high');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-cloud-provider-status-'.$this->server->uuid))->expireAfter(130)->dontRelease()];
    }

    public function handle(): void
    {
        try {
            if (! $this->server->cloudProviderToken) {
                return;
            }

            match ($this->server->cloudProviderToken->provider) {
                'hetzner' => $this->server->hetzner_server_id
                    ? $this->server->refreshHetznerState()
                    : null,
                'vultr' => $this->server->vultr_instance_id
                    ? $this->server->refreshVultrState()
                    : null,
                'digitalocean' => $this->server->digitalocean_droplet_id
                    ? $this->server->refreshDigitalOceanState()
                    : null,
                default => null,
            };
        } catch (\Throwable $e) {
            Log::debug('Cloud provider status check failed', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
