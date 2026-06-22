<?php

namespace App\Actions\V5\Proxy;

use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use Lorisleiva\Actions\Concerns\AsAction;

class StopCaddyIngress
{
    use AsAction;

    private const FIREWALL_PORTS = [80, 443];

    public function __construct(private readonly FluxClient $fluxClient) {}

    public function handle(Server $server): string
    {
        $hostId = $server->wireguard_management_ip ?: $server->node_address;

        if (! is_string($hostId) || $hostId === '') {
            return 'Server is missing its Flux host id.';
        }

        $output = $this->fluxClient->stopIngress($hostId, 'caddy');

        foreach (self::FIREWALL_PORTS as $port) {
            $this->fluxClient->revokeFirewallRule($hostId, "v5-caddy-ingress:{$port}");
        }

        if ($server->exists) {
            $server->update(['ingress_status' => 'exited']);
        }

        return $output;
    }
}
