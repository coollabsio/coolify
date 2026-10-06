<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Builds the HTTP routes that every ingress Node of a cluster serves: one route per domain of each
 * workload that has a port and runs on a Node of the cluster. Routes reference the workload UUID:
 * Sentinel resolves the healthy containers of a route from the `workload_endpoints` it reads from
 * Corrosion, keyed by the `coolify.workload` container label.
 */
class BuildNodeClusterIngressRoutes
{
    use AsAction;

    public const MAX_ROUTES = 10_000;

    public const MAX_NAMES = 10_000;

    public const NAMESPACE = 'default';

    /** @return list<array{host: string, workload_id: string, namespace: string, port: int}> */
    public function handle(NodeCluster $cluster): array
    {
        $routes = [];
        foreach (self::routedWorkloads($cluster) as $workload) {
            foreach ($workload->domains as $host) {
                // Domains are unique across workloads. Keep the first route if old data disagrees.
                $routes[$host] ??= [
                    'host' => $host,
                    'workload_id' => $workload->uuid,
                    'namespace' => self::NAMESPACE,
                    'port' => $workload->http_port,
                ];
            }
        }
        if (count($routes) > self::MAX_ROUTES) {
            throw new RuntimeException('The cluster has more than '.number_format(self::MAX_ROUTES).' domains. Remove domains to apply ingress.');
        }

        return array_values($routes);
    }

    /**
     * The internal DNS names of the cluster (`<name>.default.coolify.internal`) mapped to workload
     * UUIDs. Sentinel stores them in Corrosion, so a rename applies without a redeploy.
     *
     * @return list<array{name: string, workload_id: string, namespace: string}>
     */
    public static function names(NodeCluster $cluster): array
    {
        $names = NodeWorkload::query()
            ->where('team_id', $cluster->team_id)
            ->whereNotNull('internal_dns_name')
            ->whereHas('nodes', fn ($query) => $query->where('nodes.node_cluster_id', $cluster->id))
            ->orderBy('id')
            ->get(['id', 'uuid', 'internal_dns_name'])
            ->map(fn (NodeWorkload $workload): array => [
                'name' => $workload->internal_dns_name,
                'workload_id' => $workload->uuid,
                'namespace' => self::NAMESPACE,
            ])
            ->values()
            ->all();
        if (count($names) > self::MAX_NAMES) {
            throw new RuntimeException('The cluster has more than '.number_format(self::MAX_NAMES).' internal DNS names. Remove applications to apply the network.');
        }

        return $names;
    }

    /**
     * Workloads with domains and a port that run on a Node of the cluster. Their `nodes` relation
     * only contains the Nodes of this cluster.
     *
     * @return Collection<int, NodeWorkload>
     */
    public static function routedWorkloads(NodeCluster $cluster): Collection
    {
        return NodeWorkload::query()
            ->where('team_id', $cluster->team_id)
            ->whereNotNull('domains')
            ->whereNotNull('http_port')
            ->whereHas('nodes', fn ($query) => $query->where('nodes.node_cluster_id', $cluster->id))
            ->with(['nodes' => fn ($query) => $query->where('nodes.node_cluster_id', $cluster->id)])
            ->orderBy('id')
            ->get()
            ->filter(fn (NodeWorkload $workload): bool => $workload->hasIngressRoutes())
            ->values();
    }
}
