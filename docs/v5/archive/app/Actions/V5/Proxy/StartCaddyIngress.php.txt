<?php

namespace App\Actions\V5\Proxy;

use App\Exceptions\V5\UnsupportedCooldVerb;
use App\Models\V5\Application;
use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class StartCaddyIngress
{
    use AsAction;

    private const FIREWALL_PORTS = [80];

    public function __construct(private readonly FluxClient $fluxClient) {}

    public function handle(Server $server): string
    {
        if (! $server->isIngress()) {
            return 'Server is not an ingress server.';
        }

        $hostId = $server->fluxHostId();

        if (! is_string($hostId) || $hostId === '') {
            throw new \RuntimeException('Server is missing its Flux host id.');
        }

        $configuration = GenerateCaddyIngressConfiguration::run($this->applications($server));
        $output = $this->fluxClient->applyIngress($hostId, 'caddy', $configuration['caddyfile'], $this->ingressApps($configuration['apps']));

        $firewallWarning = null;

        foreach (self::FIREWALL_PORTS as $port) {
            try {
                $this->fluxClient->applyFirewallRule($hostId, [
                    'id' => "v5-caddy-ingress:{$port}",
                    'namespace' => 'default',
                    'src' => '0.0.0.0/0',
                    'dst' => 'coolify-v5-caddy',
                    'proto' => 'tcp',
                    'port' => $port,
                ]);
            } catch (UnsupportedCooldVerb $exception) {
                $firewallWarning = "Caddy ingress is running, but this node's coold does not support {$exception->verb}, so the managed firewall was not updated for port {$port}.";
                Log::warning('V5 caddy ingress firewall rule skipped: coold verb unsupported', [
                    'server_id' => $server->id,
                    'port' => $port,
                    'verb' => $exception->verb,
                    'message' => $exception->getMessage(),
                ]);

                break;
            }
        }

        if ($server->exists) {
            $server->update([
                'ingress_type' => 'caddy',
                'ingress_status' => 'running',
                ...($firewallWarning === null ? [] : [
                    'last_status_check' => 'flux',
                    'last_status_output' => $firewallWarning,
                ]),
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
