<?php

namespace App\Livewire\Dashboard;

use App\Actions\Node\DetermineWorkloadState;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeWorkload;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only infrastructure map on the dashboard: the current team's clusters with their
 * servers (Node models), the applications placed on each server, firewall rules, public
 * ingress, and the team's Docker servers. The map receives plain arrays only, without IPs.
 */
class ClusterMap extends Component
{
    public const DOCKER_SERVER_LIMIT = 8;

    /**
     * @var array{
     *     clusters: list<array{uuid: string, name: string, href: string, firewallHref: string, status: string, statusType: string, servers: list<array{uuid: string, name: string, href: string, status: string, statusType: string, ingress: string, ingressType: string, apps: list<array{uuid: string, name: string, href: ?string, status: string, statusType: string}>}>, rules: list<array{uuid: string, sourceType: string, sourceUuid: string, destinationUuid: string, protocol: string, port: int}>, ingress: list<array{appUuid: string, domains: list<string>, port: int}>}>,
     *     dockerServers: list<array{uuid: string, name: string, href: string, status: string, statusType: string}>,
     *     dockerServersTotal: int,
     *     serversHref: string,
     * }
     */
    #[Locked]
    public array $map = [];

    /** Whether the cluster stack, and with it the map, is available on this instance. */
    public static function isEnabled(): bool
    {
        return isDev() && (bool) config('constants.sentinel.host_enabled', false);
    }

    /** Whether the dashboard offers the map to the current team: the stack is enabled and the team has a cluster. */
    public static function isAvailableForCurrentTeam(): bool
    {
        return self::isEnabled() && NodeCluster::query()->where('team_id', currentTeam()->id)->exists();
    }

    public function mount(): void
    {
        abort_unless(self::isEnabled(), 404);
        $this->refreshMap();
    }

    public function refreshMap(): void
    {
        $this->map = $this->buildMap();
    }

    public function render(): View
    {
        return view('livewire.dashboard.cluster-map');
    }

    /** @return array<string, mixed> */
    private function buildMap(): array
    {
        $teamId = currentTeam()->id;
        $clusters = NodeCluster::query()
            ->where('team_id', $teamId)
            ->with(['nodes' => fn ($query) => $query->where('team_id', $teamId)->orderBy('name')])
            ->orderBy('name')
            ->get();
        $nodes = $clusters->flatMap(fn (NodeCluster $cluster) => $cluster->nodes)->keyBy('id');
        $workloads = $this->workloads($teamId, $nodes->keys());
        $rules = $clusters->isEmpty()
            ? collect()
            : NodeFirewallRule::query()
                ->whereIn('node_cluster_id', $clusters->modelKeys())
                ->orderBy('id')
                ->get(['uuid', 'node_cluster_id', 'source_workload_id', 'source_node_id', 'destination_workload_id', 'protocol', 'port'])
                ->groupBy('node_cluster_id');
        $dockerServers = Server::ownedByCurrentTeamCached()->sortBy('name', SORT_NATURAL)->values();

        return [
            'clusters' => $clusters
                ->map(fn (NodeCluster $cluster): array => $this->clusterData($cluster, $workloads, $nodes, $rules->get($cluster->id, collect())))
                ->values()
                ->all(),
            'dockerServers' => $dockerServers
                ->take(self::DOCKER_SERVER_LIMIT)
                ->map(function (Server $server): array {
                    $status = $server->dashboardStatus();

                    return [
                        'uuid' => $server->uuid,
                        'name' => $server->name,
                        'href' => route('server.show', ['server_uuid' => $server->uuid]),
                        'status' => $status['status'],
                        'statusType' => $status['type'],
                    ];
                })
                ->values()
                ->all(),
            'dockerServersTotal' => $dockerServers->count(),
            'serversHref' => route('server.index'),
        ];
    }

    /**
     * Applications placed on the given cluster servers, with what their state needs.
     *
     * @param  Collection<int, int>  $nodeIds
     * @return Collection<int, NodeWorkload>
     */
    private function workloads(int $teamId, Collection $nodeIds): Collection
    {
        if ($nodeIds->isEmpty()) {
            return collect();
        }

        return NodeWorkload::query()
            ->where('team_id', $teamId)
            ->whereHas('nodes', fn ($query) => $query->whereIn('nodes.id', $nodeIds))
            ->with([
                'environment.project',
                'nodes' => fn ($query) => $query->whereIn('nodes.id', $nodeIds)->select('nodes.id'),
                'revisions' => fn ($query) => $query->latest('id')->limit(1)->select(['id', 'node_workload_id']),
                'containers' => fn ($query) => $query
                    ->where('is_managed', true)
                    ->whereIn('node_id', $nodeIds)
                    ->select(['id', 'node_id', 'node_workload_id', 'node_workload_revision_id', 'state']),
            ])
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, NodeWorkload>  $workloads
     * @param  Collection<int, Node>  $nodes
     * @param  Collection<int, NodeFirewallRule>  $rules
     * @return array<string, mixed>
     */
    private function clusterData(NodeCluster $cluster, Collection $workloads, Collection $nodes, Collection $rules): array
    {
        $clusterNodeIds = $cluster->nodes->modelKeys();
        $clusterWorkloads = $workloads->filter(
            fn (NodeWorkload $workload): bool => $workload->nodes->contains(fn (Node $node): bool => in_array($node->id, $clusterNodeIds, true)),
        );

        return [
            'uuid' => $cluster->uuid,
            'name' => $cluster->name,
            'href' => route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]),
            'firewallHref' => route('node-cluster.firewall', ['cluster_uuid' => $cluster->uuid]),
            'status' => $cluster->networkStatusLabel(),
            'statusType' => $cluster->networkStatusBadgeType(),
            'servers' => $cluster->nodes->map(function (Node $node) use ($cluster, $clusterWorkloads): array {
                $status = $node->statusBadge();
                $ingress = NodeCluster::ingressBadge($cluster->nodeIngressState($node));

                return [
                    'uuid' => $node->uuid,
                    'name' => $node->name,
                    'href' => route('node.show', ['node_uuid' => $node->uuid]),
                    'status' => $status['status'],
                    'statusType' => $status['type'],
                    'ingress' => $ingress['status'],
                    'ingressType' => $ingress['type'],
                    'apps' => $clusterWorkloads
                        ->filter(fn (NodeWorkload $workload): bool => $workload->nodes->contains('id', $node->id))
                        ->map(fn (NodeWorkload $workload): array => $this->appData($workload, $node))
                        ->values()
                        ->all(),
                ];
            })->values()->all(),
            'rules' => $this->rulesData($rules, $clusterWorkloads, $nodes->only($clusterNodeIds)),
            'ingress' => $clusterWorkloads
                ->filter(fn (NodeWorkload $workload): bool => $workload->hasIngressRoutes())
                ->map(fn (NodeWorkload $workload): array => [
                    'appUuid' => $workload->uuid,
                    'domains' => array_values($workload->domains),
                    'port' => $workload->http_port,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array{uuid: string, name: string, href: ?string, status: string, statusType: string} */
    private function appData(NodeWorkload $workload, Node $node): array
    {
        $state = DetermineWorkloadState::make()->fromInventory(
            $node,
            fn () => $workload->revisions->first(),
            fn () => $workload->containers->where('node_id', $node->id)->values(),
        );
        $environment = $workload->environment;
        $project = $environment?->project;

        return [
            'uuid' => $workload->uuid,
            'name' => $workload->name,
            'href' => $project ? route('project.cluster-application.show', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'workload_uuid' => $workload->uuid,
            ]) : null,
            'status' => str($state->value)->title()->toString(),
            'statusType' => $state->badgeType(),
        ];
    }

    /**
     * Rules whose source and destination are on this cluster, in the firewall canvas format.
     *
     * @param  Collection<int, NodeFirewallRule>  $rules
     * @param  Collection<int, NodeWorkload>  $workloads  applications of this cluster, keyed by id
     * @param  Collection<int, Node>  $nodes  servers of this cluster, keyed by id
     * @return list<array{uuid: string, sourceType: string, sourceUuid: string, destinationUuid: string, protocol: string, port: int}>
     */
    private function rulesData(Collection $rules, Collection $workloads, Collection $nodes): array
    {
        return $rules
            ->map(function (NodeFirewallRule $rule) use ($workloads, $nodes): ?array {
                $destination = $workloads->get($rule->destination_workload_id);
                $source = $rule->source_node_id !== null
                    ? $nodes->get($rule->source_node_id)
                    : $workloads->get($rule->source_workload_id);
                if ($destination === null || $source === null) {
                    return null;
                }

                return [
                    'uuid' => $rule->uuid,
                    'sourceType' => $source instanceof Node ? 'node' : 'workload',
                    'sourceUuid' => $source->uuid,
                    'destinationUuid' => $destination->uuid,
                    'protocol' => $rule->protocol,
                    'port' => $rule->port,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
