<?php

namespace App\Events;

use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\Server as V5Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class V5ClusterUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $teamId, public int $clusterId) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("team.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'v5.cluster.updated';
    }

    /**
     * @return array{cluster: array<string, mixed>|null}
     */
    public function broadcastWith(): array
    {
        $cluster = V5Cluster::query()
            ->where('team_id', $this->teamId)
            ->with(['servers' => fn ($query) => $query
                ->with('privateKey')
                ->orderBy('name')])
            ->withCount('servers')
            ->find($this->clusterId);

        return [
            'cluster' => $cluster instanceof V5Cluster ? $this->serializeCluster($cluster) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCluster(V5Cluster $cluster): array
    {
        return [
            'id' => (string) $cluster->id,
            'name' => $cluster->name,
            'description' => $cluster->description,
            'wireguardInterface' => $cluster->wireguard_interface,
            'wireguardManagementPool' => $cluster->wireguard_management_pool,
            'wireguardListenPort' => $cluster->wireguard_listen_port,
            'containerNetworkPool' => $cluster->container_network_pool,
            'containerNetworkPrefix' => $cluster->container_network_prefix,
            'namespaces' => $cluster->namespaces ?? V5Cluster::DEFAULT_NAMESPACES,
            'defaultDenyContainers' => $cluster->default_deny_containers,
            'cooldVersion' => $cluster->coold_version,
            'corrosionVersion' => $cluster->corrosion_version,
            'corrosionGossipPort' => $cluster->corrosion_gossip_port,
            'corrosionApiPort' => $cluster->corrosion_api_port,
            'builderEnabled' => $cluster->builder_enabled,
            'builderCapacity' => $cluster->builder_capacity,
            'builderCpuQuota' => $cluster->builder_cpu_quota,
            'builderMemoryMax' => $cluster->builder_memory_max,
            'builderTimeoutSecs' => $cluster->builder_timeout_secs,
            'lastCliAction' => $cluster->last_cli_action,
            'lastCliStatus' => $cluster->last_cli_status,
            'lastCliSummary' => $cluster->last_cli_summary,
            'lastCliRanAt' => $cluster->last_cli_ran_at?->toJSON(),
            'serversCount' => $cluster->servers_count ?? $cluster->servers->count(),
            'servers' => $cluster->servers->map(fn (V5Server $server) => [
                'id' => (string) $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'status' => $server->status,
                'capabilities' => $server->capabilities ?? [],
                'builderEnabled' => $server->builder_enabled,
                'builderCapacity' => $server->builder_capacity,
                'builderCpuQuota' => $server->builder_cpu_quota,
                'uuid' => $server->uuid,
                'nodeAddress' => $server->node_address,
                'wireguardListenPortOverride' => $server->wireguard_listen_port_override,
                'wireguardEndpointOverride' => $server->wireguard_endpoint_override,
                'wireguardManagementIp' => $server->wireguard_management_ip,
                'wireguardPublicKey' => $server->wireguard_public_key,
                'containerSubnets' => $server->container_subnets ?? [],
                'privateKeyName' => $server->privateKey?->name,
                'lastBootstrappedAt' => $server->last_bootstrapped_at?->toJSON(),
                'lastBootstrapAction' => $server->last_bootstrap_action,
                'lastBootstrapStatus' => $server->last_bootstrap_status,
                'lastBootstrapOutput' => $server->last_bootstrap_output,
                'lastBootstrapRanAt' => $server->last_bootstrap_ran_at?->toJSON(),
            ])->all(),
        ];
    }
}
