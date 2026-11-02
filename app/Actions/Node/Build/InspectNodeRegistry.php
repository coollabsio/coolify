<?php

namespace App\Actions\Node\Build;

use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeRegistry;
use App\Models\NodeWorkload;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Reads the image registry report of a cluster from its Nodes
 * (`registry.inspect.v1`): health and repositories from the registry Node,
 * disk use of the registry and build cache from every Node.
 */
class InspectNodeRegistry
{
    use AsAction;

    /**
     * @return array{
     *     healthy: ?bool,
     *     error: ?string,
     *     truncated: bool,
     *     repositories: list<array{name: string, workload: ?array{name: string, uuid: string, project_uuid: ?string, environment_uuid: ?string}, tags: list<array{tag: string, digest: string, deployed: bool}>}>,
     *     nodes: list<array{name: string, registry_running: bool, builder_running: bool, registry_bytes: int, build_cache_bytes: int, error: ?string}>,
     *     inspected_at: string
     * }
     */
    public function handle(NodeCluster $cluster): array
    {
        $report = ['healthy' => null, 'error' => null, 'truncated' => false, 'repositories' => [], 'nodes' => [], 'inspected_at' => now()->toIso8601String()];
        foreach ($cluster->nodes()->orderBy('id')->get() as $node) {
            $report['nodes'][] = $this->inspectNode($node, $report);
        }
        if ($report['healthy'] === null && $cluster->registry?->node_id !== null) {
            $report['healthy'] = false;
            $report['error'] ??= 'The registry server did not report. Check that it is online and runs the latest Sentinel.';
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{name: string, registry_running: bool, builder_running: bool, registry_bytes: int, build_cache_bytes: int, error: ?string}
     */
    private function inspectNode(Node $node, array &$report): array
    {
        $row = ['name' => $node->name, 'registry_running' => false, 'builder_running' => false, 'registry_bytes' => 0, 'build_cache_bytes' => 0, 'error' => null];
        if (! $node->canReceiveNetworkCommands()) {
            $row['error'] = 'Offline';

            return $row;
        }
        if ($node->supportsCapability(NodeRegistry::INSPECT_CAPABILITY) !== true) {
            $row['error'] = 'Upgrade Sentinel to see this server';

            return $row;
        }
        try {
            $result = RunImageBuild::flux('registry.inspect', ['server_id' => $node->uuid, 'command_id' => (string) Str::uuid()], 130);
        } catch (Throwable $exception) {
            $row['error'] = mb_substr($exception->getMessage(), 0, 300);

            return $row;
        }
        $row['registry_running'] = (bool) data_get($result, 'registry_running');
        $row['builder_running'] = (bool) data_get($result, 'builder_running');
        $row['registry_bytes'] = (int) data_get($result, 'registry_bytes', 0);
        $row['build_cache_bytes'] = (int) data_get($result, 'build_cache_bytes', 0);
        if ($row['registry_running']) {
            $report['healthy'] = (bool) data_get($result, 'healthy');
            $report['error'] = filled(data_get($result, 'error')) ? mb_substr((string) data_get($result, 'error'), 0, 500) : null;
            $report['truncated'] = (bool) data_get($result, 'repositories_truncated');
            $report['repositories'] = $this->repositories((array) data_get($result, 'repositories', []), $node);
        }

        return $row;
    }

    /**
     * Repositories are named after the workload UUID; the deployed tag is the one whose digest
     * the latest revision of that workload uses.
     *
     * @param  array<int, mixed>  $repositories
     * @return list<array{name: string, workload: ?array{name: string, uuid: string, project_uuid: ?string, environment_uuid: ?string}, tags: list<array{tag: string, digest: string, deployed: bool}>}>
     */
    private function repositories(array $repositories, Node $node): array
    {
        $names = collect($repositories)->pluck('name')->filter(fn ($name): bool => is_string($name))->values();
        $workloads = NodeWorkload::query()
            ->where('team_id', $node->team_id)
            ->whereIn('uuid', $names)
            ->with(['project:id,uuid', 'environment:id,uuid', 'revisions' => fn ($query) => $query->latest('id')->limit(1)])
            ->get()
            ->keyBy('uuid');

        return collect($repositories)
            ->filter(fn ($repository): bool => is_string(data_get($repository, 'name')))
            ->map(function ($repository) use ($workloads): array {
                $workload = $workloads->get($repository['name']);
                $deployedDigest = Str::after((string) $workload?->revisions->first()?->image, '@');

                return [
                    'name' => $repository['name'],
                    'workload' => $workload === null ? null : [
                        'name' => $workload->name,
                        'uuid' => $workload->uuid,
                        'project_uuid' => $workload->project?->uuid,
                        'environment_uuid' => $workload->environment?->uuid,
                    ],
                    'tags' => collect((array) data_get($repository, 'tags', []))
                        ->map(fn ($tag): array => [
                            'tag' => (string) data_get($tag, 'tag'),
                            'digest' => (string) data_get($tag, 'digest'),
                            'deployed' => filled(data_get($tag, 'digest')) && data_get($tag, 'digest') === $deployedDigest,
                        ])
                        ->sortByDesc('deployed')
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $repository): string => $repository['workload']['name'] ?? '~'.$repository['name'])
            ->values()
            ->all();
    }
}
