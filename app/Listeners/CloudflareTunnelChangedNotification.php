<?php

namespace App\Listeners;

use App\Events\CloudflareTunnelChanged;
use App\Events\CloudflareTunnelConfigured;
use App\Models\Server;
use Illuminate\Support\Sleep;

class CloudflareTunnelChangedNotification
{
    public Server $server;

    public function __construct() {}

    public function handle(CloudflareTunnelChanged $event): void
    {
        $server_id = data_get($event, 'data.server_id');
        $ssh_domain = data_get($event, 'data.ssh_domain');

        $this->server = Server::where('id', $server_id)->firstOrFail();

        // Check if cloudflare tunnel is running (container is healthy) - try 3 times with 5 second intervals
        $cloudflareHealthy = false;
        $attempts = 3;

        for ($i = 1; $i <= $attempts; $i++) {
            \Log::debug("Cloudflare health check attempt {$i}/{$attempts}", ['server_id' => $server_id]);
            $result = instant_remote_process_with_timeout(['docker inspect coolify-cloudflared | jq -e ".[0].State.Health.Status == \"healthy\""'], $this->server, false, 10);

            if (blank($result)) {
                \Log::debug("Cloudflare Tunnels container not found on attempt {$i}", ['server_id' => $server_id]);
            } elseif ($result === 'true') {
                \Log::debug("Cloudflare Tunnels container healthy on attempt {$i}", ['server_id' => $server_id]);
                $cloudflareHealthy = true;
                break;
            } else {
                \Log::debug("Cloudflare Tunnels container not healthy on attempt {$i}", ['server_id' => $server_id, 'result' => $result]);
            }

            // Sleep between attempts (except after the last attempt)
            if ($i < $attempts) {
                Sleep::for(5)->seconds();
            }
        }

        if (! $cloudflareHealthy) {
            \Log::error('Cloudflare Tunnels container failed all health checks.', ['server_id' => $server_id, 'attempts' => $attempts]);

            return;
        }
        $this->server->settings->update([
            'is_cloudflare_tunnel' => true,
        ]);

        // Only update IP if it's not already set to the ssh_domain or if it's empty
        if ($this->server->ip !== $ssh_domain && ! empty($ssh_domain)) {
            \Log::debug('Cloudflare Tunnels configuration updated - updating IP address.', ['old_ip' => $this->server->ip, 'new_ip' => $ssh_domain]);
            $this->server->update(['ip' => $ssh_domain]);
        } else {
            \Log::debug('Cloudflare Tunnels configuration updated - IP address unchanged.', ['current_ip' => $this->server->ip]);
        }
        $teamId = $this->server->team_id;
        CloudflareTunnelConfigured::dispatch($teamId);
    }
}
