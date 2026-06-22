<?php

namespace App\Actions\V5\Proxy;

use App\Models\V5\Application;
use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class StartCaddyIngress
{
    use AsAction;

    private const FIREWALL_RULE_ID = 'v5-caddy-ingress:80';

    public function __construct(private readonly FluxClient $fluxClient) {}

    public function handle(Server $server): string
    {
        if (! $server->isIngress()) {
            return 'Server is not an ingress server.';
        }

        $hostId = $server->wireguard_management_ip ?: $server->node_address;

        if (! is_string($hostId) || $hostId === '') {
            return 'Server is missing its Flux host id.';
        }

        $configuration = GenerateCaddyIngressConfiguration::run($this->applications($server));
        $output = $this->fluxClient->applyIngress($hostId, 'caddy', $configuration['caddyfile'], $this->ingressApps($configuration['apps']));
        $this->fluxClient->applyFirewallRule($hostId, [
            'id' => self::FIREWALL_RULE_ID,
            'namespace' => 'default',
            'src' => '0.0.0.0/0',
            'dst' => 'coolify-v5-caddy',
            'proto' => 'tcp',
            'port' => 80,
        ]);

        if ($server->exists) {
            $server->update([
                'ingress_type' => 'caddy',
                'ingress_status' => 'running',
            ]);
        }

        return $output;
    }

    /**
     * @param  array<int, array{name: string, caddyfile: string}>  $apps
     * @return array<int, array{name: string, config: string}>
     */
    private function ingressApps(array $apps): array
    {
        return array_map(
            fn (array $app): array => [
                'name' => $app['name'],
                'config' => $app['caddyfile'],
            ],
            $apps
        );
    }

    /**
     * @return Collection<int, Application>
     */
    private function applications(Server $server): Collection
    {
        if (! $server->exists) {
            return collect();
        }

        return Application::query()
            ->where('team_id', $server->team_id)
            ->where('server_id', $server->id)
            ->with('domains')
            ->orderBy('name')
            ->get();
    }
}
