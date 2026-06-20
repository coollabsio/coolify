<?php

namespace App\Actions\V5\Proxy;

use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use Lorisleiva\Actions\Concerns\AsAction;

class StopCaddyIngress
{
    use AsAction;

    public function __construct(private readonly FluxClient $fluxClient) {}

    public function handle(Server $server): string
    {
        $hostId = $server->wireguard_management_ip ?: $server->node_address;

        if (! is_string($hostId) || $hostId === '') {
            return 'Server is missing its Flux host id.';
        }

        $output = $this->fluxClient->stopCaddyIngress($hostId);

        if ($server->exists) {
            $server->update(['caddy_ingress_status' => 'exited']);
        }

        return $output;
    }
}
