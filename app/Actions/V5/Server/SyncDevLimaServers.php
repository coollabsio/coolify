<?php

namespace App\Actions\V5\Server;

use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use App\Models\V5\Server;
use Lorisleiva\Actions\Concerns\AsAction;

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

        $capabilities = ['coold'];

        foreach ($servers as $server) {
            $wireguardManagementIp = $server['wireguard_management_ip'] ?? null;
            $values = [
                'created_by_user_id' => $user->id,
                'private_key_id' => $privateKey?->id,
                'host' => $server['host'],
                'ssh_user' => $server['ssh_user'],
                'ssh_port' => $server['ssh_port'],
                'status' => 'installed',
                'capabilities' => $capabilities,
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
