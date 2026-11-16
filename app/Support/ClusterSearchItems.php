<?php

namespace App\Support;

use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Ctrl+K search entries of the Node product: cluster applications and databases (stack services
 * included, like the resource list), cluster stacks, clusters, and cluster servers.
 *
 * The settings pages of an entry are only keys in `pages`. The palette turns them into entries like
 * "nginx > Domains" with pageRegistry(), and shows them after the resources, so a team with many cluster
 * applications does not send hundreds of page entries to the browser on each open.
 *
 * The entries of a team are cached for every team-wide user, so visibleTo() drops the pages that a user
 * may not open after the cache.
 */
final class ClusterSearchItems
{
    /** @var list<string> */
    private const TYPES = ['cluster-application', 'cluster-stack', 'cluster', 'cluster-server'];

    /** @var array<string, array{route: string, parameters: array<string, string>}> */
    private const PARENT_ROUTES = [
        'cluster-application' => ['route' => 'project.cluster-application.show', 'parameters' => ['project_uuid' => 'p', 'environment_uuid' => 'e', 'workload_uuid' => 'w']],
        'cluster' => ['route' => 'node-cluster.show', 'parameters' => ['cluster_uuid' => 'c']],
        'cluster-server' => ['route' => 'node.show', 'parameters' => ['node_uuid' => 'n']],
    ];

    /**
     * The settings pages of each entry type, in the order of its sidebar. `when` names a condition of
     * the record (see pageKeys()), `requires` a condition of the user (see visibleTo()).
     *
     * @var array<string, array<string, array{label: string, route: string, keywords: string, when?: string, requires?: string}>>
     */
    private const PAGES = [
        // x-cluster-applications.sidebar
        'cluster-application' => [
            'domains' => ['label' => 'Domains', 'route' => 'project.cluster-application.domains', 'keywords' => 'fqdn urls https', 'when' => 'application'],
            'ports' => ['label' => 'Published Ports', 'route' => 'project.cluster-application.ports', 'keywords' => 'tcp udp publish'],
            'environment-variables' => ['label' => 'Environment Variables', 'route' => 'project.cluster-application.environment-variables', 'keywords' => 'env secrets'],
            'storage' => ['label' => 'Persistent Storage', 'route' => 'project.cluster-application.storage', 'keywords' => 'volumes files'],
            'healthcheck' => ['label' => 'Healthcheck', 'route' => 'project.cluster-application.healthcheck', 'keywords' => 'health check'],
            'logs' => ['label' => 'Runtime Logs', 'route' => 'project.cluster-application.logs', 'keywords' => 'container logs'],
            'deployments' => ['label' => 'Deployment Logs', 'route' => 'project.cluster-application.deployments', 'keywords' => 'deployments builds'],
            'terminal' => ['label' => 'Terminal', 'route' => 'project.cluster-application.terminal', 'keywords' => 'shell console', 'requires' => 'terminal'],
            'servers' => ['label' => 'Servers', 'route' => 'project.cluster-application.servers', 'keywords' => 'nodes placement move'],
            'previews' => ['label' => 'Previews', 'route' => 'project.cluster-application.previews', 'keywords' => 'pull requests preview deployments', 'when' => 'previews'],
            'scheduled-tasks' => ['label' => 'Scheduled Tasks', 'route' => 'project.cluster-application.scheduled-tasks', 'keywords' => 'cron jobs'],
            'webhooks' => ['label' => 'Webhooks', 'route' => 'project.cluster-application.webhooks', 'keywords' => 'deploy webhook git'],
            'backups' => ['label' => 'Backups', 'route' => 'project.cluster-application.backups', 'keywords' => 'volume backups restore', 'when' => 'volumes'],
            'resource-limits' => ['label' => 'Resource Limits', 'route' => 'project.cluster-application.resource-limits', 'keywords' => 'cpu memory'],
            'danger' => ['label' => 'Danger Zone', 'route' => 'project.cluster-application.danger', 'keywords' => 'delete remove', 'requires' => 'delete'],
        ],
        // x-node-cluster.sidebar
        'cluster' => [
            'advanced' => ['label' => 'Advanced', 'route' => 'node-cluster.advanced', 'keywords' => 'pressure thresholds'],
            'resources' => ['label' => 'Resources', 'route' => 'node-cluster.resources', 'keywords' => 'applications workloads usage'],
            'nodes' => ['label' => 'Servers', 'route' => 'node-cluster.nodes', 'keywords' => 'nodes members ingress'],
            'firewall' => ['label' => 'Firewall', 'route' => 'node-cluster.firewall', 'keywords' => 'rules ports'],
            'delete' => ['label' => 'Danger Zone', 'route' => 'node-cluster.delete', 'keywords' => 'delete remove', 'requires' => 'admin'],
        ],
        // x-node.sidebar
        'cluster-server' => [
            'internal-dns' => ['label' => 'Internal DNS', 'route' => 'node.internal-dns', 'keywords' => 'dns names'],
            'sentinel' => ['label' => 'Sentinel', 'route' => 'node.sentinel', 'keywords' => 'agent version'],
            'workloads' => ['label' => 'Applications', 'route' => 'node.workloads', 'keywords' => 'workloads'],
            'containers' => ['label' => 'Containers', 'route' => 'node.containers', 'keywords' => 'podman'],
            'terminal' => ['label' => 'Terminal', 'route' => 'node.command', 'keywords' => 'shell console', 'requires' => 'terminal'],
            'metrics' => ['label' => 'Metrics', 'route' => 'node.metrics', 'keywords' => 'cpu memory disk'],
            'activity' => ['label' => 'Activity', 'route' => 'node.activity', 'keywords' => 'operations events'],
            'logs' => ['label' => 'Logs', 'route' => 'node.logs', 'keywords' => 'sentinel logs', 'requires' => 'admin'],
            'danger' => ['label' => 'Danger Zone', 'route' => 'node.danger', 'keywords' => 'delete remove', 'requires' => 'admin'],
        ],
    ];

    /**
     * The entries of the current team. Clusters and cluster servers only for team-wide users, because
     * their pages are team-wide only (`access.team-wide`). Workloads and stacks follow the access grants
     * through their global scope.
     *
     * @return list<array<string, mixed>>
     */
    public static function resources(int $teamId, bool $includeClusters): array
    {
        $items = self::workloads($teamId)->merge(self::stacks($teamId));
        if ($includeClusters) {
            $items = $items->merge(self::clusters($teamId))->merge(self::nodes($teamId));
        }

        return $items->values()->all();
    }

    /**
     * Drops every entry when Nodes are off, and the pages that the user may not open: Danger Zone of a
     * cluster application without `delete` on it (as its sidebar), Danger Zone of a cluster or server and
     * Logs for users who are not an admin of the team (the delete and manageSentinel policies), and
     * Terminal without the canAccessTerminal gate.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function visibleTo(array $items, User $user, int $teamId): array
    {
        $nodesEnabled = nodesEnabled();
        $granted = [
            'admin' => $user->isAdminOfTeam($teamId),
            'terminal' => $user->can('canAccessTerminal'),
        ];

        $deletable = ! $nodesEnabled ? collect() : NodeWorkload::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('id', collect($items)->where('type', 'cluster-application')->pluck('id'))
            ->with('stack')
            ->get()
            ->filter(fn (NodeWorkload $workload): bool => $user->can('delete', $workload))
            ->pluck('id')
            ->flip();

        return collect($items)
            ->reject(fn (array $item): bool => ! $nodesEnabled && in_array($item['type'] ?? null, self::TYPES, true))
            ->map(function (array $item) use ($granted, $deletable): array {
                if (isset($item['pages'])) {
                    $item['pages'] = array_values(array_filter(
                        $item['pages'],
                        fn (string $key): bool => match ($requires = self::PAGES[$item['type']][$key]['requires'] ?? null) {
                            null => true,
                            'delete' => $deletable->has($item['id']),
                            default => $granted[$requires],
                        },
                    ));
                }

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * The label, search words, and path after the parent link of each page, for the palette.
     *
     * @return array<string, array<string, array{label: string, keywords: string, path: string}>>
     */
    public static function pageRegistry(): array
    {
        return collect(self::PAGES)->map(function (array $pages, string $type): array {
            $parent = self::PARENT_ROUTES[$type];
            $parentPath = route($parent['route'], $parent['parameters'], false);

            return collect($pages)->map(fn (array $page): array => [
                'label' => $page['label'],
                'keywords' => $page['keywords'],
                'path' => Str::after(route($page['route'], $parent['parameters'], false), $parentPath),
            ])->all();
        })->all();
    }

    private static function workloads(int $teamId): Collection
    {
        return NodeWorkload::query()
            ->where('team_id', $teamId)
            ->whereNull('preview_of_id')
            ->whereNotNull('environment_id')
            ->with([
                'project:id,uuid,name',
                'environment:id,uuid,name',
                'revisions' => fn ($revisions) => $revisions->select('id', 'node_workload_id', 'image')->latest('id')->limit(1),
            ])
            ->withExists(['volumes as has_volumes' => fn ($volumes) => $volumes->whereNull('detached_at')])
            ->orderBy('name')
            ->get()
            ->filter(fn (NodeWorkload $workload): bool => $workload->project !== null && $workload->environment !== null)
            ->map(function (NodeWorkload $workload): array {
                $badge = match (true) {
                    $workload->node_stack_id !== null => 'Stack service',
                    $workload->isDatabase() => 'Cluster database',
                    default => 'Cluster application',
                };
                $image = $workload->revisions->first()?->image ?? $workload->docker_registry_image_name;

                return [
                    'id' => $workload->id,
                    'name' => $workload->name,
                    'type' => 'cluster-application',
                    'badge' => $badge,
                    'uuid' => $workload->uuid,
                    'description' => $workload->description ?: ($workload->git_repository ?? $image),
                    'link' => route('project.cluster-application.show', [
                        'project_uuid' => $workload->project->uuid,
                        'environment_uuid' => $workload->environment->uuid,
                        'workload_uuid' => $workload->uuid,
                    ]),
                    'project' => $workload->project->name,
                    'environment' => $workload->environment->name,
                    'search_text' => strtolower(implode(' ', array_filter([
                        $workload->name,
                        $workload->uuid,
                        $workload->description,
                        $image,
                        $workload->git_repository,
                        $workload->database_type?->value,
                        $badge,
                        'cluster application applications',
                    ]))),
                    'pages' => self::pageKeys('cluster-application', [
                        'application' => ! $workload->isDatabase(),
                        'previews' => $workload->canHavePreviews(),
                        'volumes' => (bool) $workload->has_volumes,
                    ]),
                ];
            });
    }

    private static function stacks(int $teamId): Collection
    {
        return NodeStack::query()
            ->where('team_id', $teamId)
            ->whereNotNull('environment_id')
            ->with(['project:id,uuid,name', 'environment:id,uuid,name', 'cluster:id,name'])
            ->orderBy('name')
            ->get()
            ->filter(fn (NodeStack $stack): bool => $stack->project !== null && $stack->environment !== null)
            ->map(fn (NodeStack $stack): array => [
                'id' => $stack->id,
                'name' => $stack->name,
                'type' => 'cluster-stack',
                'badge' => 'Cluster stack',
                'uuid' => $stack->uuid,
                'description' => $stack->cluster?->name,
                'link' => route('project.cluster-stack.show', [
                    'project_uuid' => $stack->project->uuid,
                    'environment_uuid' => $stack->environment->uuid,
                    'stack_uuid' => $stack->uuid,
                ]),
                'project' => $stack->project->name,
                'environment' => $stack->environment->name,
                'search_text' => strtolower(implode(' ', array_filter([
                    $stack->name,
                    $stack->uuid,
                    $stack->template,
                    $stack->git_repository,
                    'cluster stack stacks compose service services',
                ]))),
            ]);
    }

    private static function clusters(int $teamId): Collection
    {
        return NodeCluster::query()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get()
            ->map(fn (NodeCluster $cluster): array => [
                'id' => $cluster->id,
                'name' => $cluster->name,
                'type' => 'cluster',
                'badge' => 'Cluster',
                'uuid' => $cluster->uuid,
                'description' => $cluster->description,
                'link' => route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]),
                'project' => null,
                'environment' => null,
                'search_text' => strtolower($cluster->name.' '.$cluster->description.' '.$cluster->cidr.' cluster clusters'),
                'pages' => self::pageKeys('cluster'),
            ]);
    }

    private static function nodes(int $teamId): Collection
    {
        return Node::query()
            ->where('team_id', $teamId)
            ->with('cluster:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Node $node): array => [
                'id' => $node->id,
                'name' => $node->name,
                'type' => 'cluster-server',
                'badge' => 'Cluster server',
                'uuid' => $node->uuid,
                'description' => $node->description ?: $node->cluster?->name,
                'link' => route('node.show', ['node_uuid' => $node->uuid]),
                'project' => null,
                'environment' => null,
                'search_text' => strtolower(implode(' ', array_filter([
                    $node->name,
                    $node->ip,
                    $node->description,
                    $node->cluster?->name,
                    'cluster server servers node nodes',
                ]))),
                'pages' => self::pageKeys('cluster-server'),
            ]);
    }

    /**
     * @param  array<string, bool>  $conditions
     * @return list<string>
     */
    private static function pageKeys(string $type, array $conditions = []): array
    {
        return collect(self::PAGES[$type])
            ->filter(fn (array $page): bool => ! isset($page['when']) || $conditions[$page['when']])
            ->keys()
            ->all();
    }
}
