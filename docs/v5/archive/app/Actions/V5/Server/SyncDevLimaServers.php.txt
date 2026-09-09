<?php

namespace App\Actions\V5\Server;

use App\Enums\V5\ServerStatus;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use App\Models\V5\Server;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registers local Lima development VMs (provisioned by scripts/dev.sh) as
 * cluster servers. They are intentionally seeded as Installed with
 * last_bootstrapped_at already set but has_coold=false, so they skip the real
 * bootstrap flow by design: V5BootstrapServerJob early-returns on a non-null
 * last_bootstrapped_at, and V5ReconcileServersJob ignores them until
 * something marks has_coold=true.
 */
class SyncDevLimaServers
{
    use AsAction;

    /**
     * @param  array<int, array{
     *     name: string,
     *     host: string,
     *     ssh_user: string,
     *     ssh_port: int,
     *     wireguard_management_ip?: ?string,
     *     wireguard_listen_port_override?: ?int,
     *     wireguard_endpoint_override?: ?string
     * }>  $servers
     */
    public function handle(
        Team $team,
        User $user,
        ?PrivateKey $privateKey,
        string $clusterName,
        array $servers,
    ): Cluster {
        $cluster = Cluster::query()->updateOrCreate([
            'team_id' => $team->id,
            'name' => $clusterName,
        ], [
            'created_by_user_id' => $user->id,
            'description' => 'Local Lima development cluster managed by scripts/dev.sh.',
        ]);

        foreach ($servers as $server) {
            $wireguardManagementIp = $server['wireguard_management_ip'] ?? null;
            $values = [
                'created_by_user_id' => $user->id,
                'private_key_id' => $privateKey?->id,
                'host' => $server['host'],
                'ssh_user' => $server['ssh_user'],
                'ssh_port' => $server['ssh_port'],
                'status' => ServerStatus::Installed->value,
                'has_coold' => false,
                'is_ingress' => false,
                'builder_enabled' => false,
                'builder_capacity' => 0,
                'node_address' => $wireguardManagementIp ?: $server['host'],
                'wireguard_management_ip' => $wireguardManagementIp,
                'last_bootstrapped_at' => now(),
            ];

            if (array_key_exists('wireguard_listen_port_override', $server)) {
                $values['wireguard_listen_port_override'] = $server['wireguard_listen_port_override'];
            }

            if (array_key_exists('wireguard_endpoint_override', $server)) {
                $values['wireguard_endpoint_override'] = $server['wireguard_endpoint_override'];
            }

            Server::query()->updateOrCreate([
                'team_id' => $team->id,
                'cluster_id' => $cluster->id,
                'name' => $server['name'],
            ], $values);
        }

        return $cluster->refresh();
    }
}
