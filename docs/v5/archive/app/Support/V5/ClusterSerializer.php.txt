<?php

namespace App\Support\V5;

use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\Server as V5Server;

/**
 * Single source of truth for the cluster payload served by the Clusters
 * Inertia props and broadcast by V5ClusterUpdated — the two must stay
 * identical for websocket vs. initial-load parity.
 */
class ClusterSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(V5Cluster $cluster): array
    {
        return [
            'id' => $cluster->uuid,
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
                'id' => $server->uuid,
                'name' => $server->name,
                'host' => $server->host,
                'status' => $server->status,
                'capabilities' => $server->capabilities ?? [],
                'builderEnabled' => $server->builder_enabled,
                'builderCapacity' => $server->builder_capacity,
                'builderCpuQuota' => $server->builder_cpu_quota,
                'ingressEnabled' => $server->isIngress(),
                'ingressType' => $server->ingress_type,
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
                'lastStatusOutput' => $server->last_status_output,
                'lastStatusCheckedAt' => $server->last_status_checked_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * Reload servers (with keys) and counts before serializing so the payload
     * always reflects the latest database state.
     *
     * @return array<string, mixed>
     */
    public function serializeFresh(V5Cluster $cluster): array
    {
        $cluster->load(['servers' => fn ($query) => $query
            ->with('privateKey')
            ->orderBy('name')]);
        $cluster->loadCount('servers');

        return $this->serialize($cluster);
    }
}
