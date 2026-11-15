<?php

namespace App\Services\NodeStack;

use App\Actions\Node\UpdateNodeWorkloadDomains;
use App\Enums\NodeWorkloadSourceType;
use App\Models\EnvironmentVariable;
use App\Models\NodePublishedPort;
use App\Models\NodeStack;
use App\Models\NodeVolume;
use App\Models\NodeVolumeMount;
use App\Models\NodeWorkload;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * What a new Compose file of a stack from Git changes in the stack (decision 0023, step 2). It
 * reads the current workloads and writes nothing; SyncNodeStackCompose applies it.
 *
 * The three-way rule decides each value that the Compose file owns: when the current value equals
 * the value of the previous file, the value of the new file is taken. Otherwise the user changed
 * it, and the current value stays. The health check of the file (`stack_health_check`) is always
 * taken, because the user sets an own health check in other fields.
 */
final readonly class NodeStackComposeDiff
{
    /** The keys of a revision configuration that the Compose file owns. */
    private const REVISION_KEYS = ['command', 'restart_policy', 'files'];

    /**
     * @param  list<array<string, mixed>>  $added  The services of the new file without a workload; see NodeStackComposeMapper::services().
     * @param  array<string, ?int>  $placements  The Node of each added service, by service: the Node of its volume group, else of the first service. Null when the stack has no placed service.
     * @param  list<NodeWorkload>  $removed  The workloads whose service is not in the new file.
     * @param  list<NodeStackServiceChange>  $changed
     * @param  list<array{service: string, key: string, name: string, path: string, read_only: bool}>  $newMounts  Mounts of shared volumes to create; see NodeStackComposeMapper::stackMounts().
     * @param  array<string, NodeVolume>  $volumes  The existing shared volumes that the new file mounts, by key.
     * @param  array<int, array{service: string, mount_path: string, read_only: bool}>  $updatedMounts  By NodeVolumeMount id.
     * @param  array<int, string>  $removedMounts  The service of each mount that left the file, by NodeVolumeMount id. The volume stays (decision 0024).
     */
    public function __construct(
        public array $added,
        public array $placements,
        public array $removed,
        public array $changed,
        public array $newMounts,
        public array $volumes,
        public array $updatedMounts,
        public array $removedMounts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $previous  The services of the stored Compose file, with the stored variables.
     * @param  list<array<string, mixed>>  $next  The services of the new file, with the stored and the new variables.
     * @param  Collection<string, NodeWorkload>  $workloads  The workloads of the stack, by service.
     *
     * @throws RuntimeException When the new file needs a change that would lose or split data.
     */
    public static function between(NodeStack $stack, array $previous, array $next, Collection $workloads): self
    {
        $previousServices = collect($previous)->keyBy('service')->all();
        $nextServices = collect($next)->keyBy('service')->all();

        $removed = [];
        foreach ($workloads as $service => $workload) {
            if (isset($nextServices[$service])) {
                continue;
            }
            if ($workload->volumes()->exists()) {
                throw new RuntimeException("The service {$service} is not in the Compose file any more. It has volumes; delete it on its page to delete its data, or add it back.");
            }
            $removed[] = $workload;
        }

        $added = [];
        $changed = [];
        foreach ($next as $service) {
            $workload = $workloads->get($service['service']);
            if ($workload === null) {
                $added[] = $service;

                continue;
            }
            $change = self::change($stack, $workload, $previousServices[$service['service']] ?? null, $service);
            if (! $change->isEmpty()) {
                $changed[] = $change;
            }
        }

        [$volumes, $newMounts, $updatedMounts, $removedMounts] = self::mounts($previous, $next, $workloads);
        $placements = self::placements($added, $next, $workloads, $volumes);
        self::ensureOneNodePerVolume($next, $workloads, $volumes, $placements);

        return new self($added, $placements, $removed, $changed, $newMounts, $volumes, $updatedMounts, $removedMounts);
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [] && $this->changed === []
            && $this->newMounts === [] && $this->updatedMounts === [] && $this->removedMounts === [];
    }

    /**
     * The published ports of every service of the stack after the change, by service.
     *
     * @param  Collection<string, NodeWorkload>  $workloads  The workloads of the stack, by service.
     * @return array<string, list<array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>}>>
     */
    public function publishedPorts(Collection $workloads): array
    {
        $removed = array_map(fn (NodeWorkload $workload): string => (string) $workload->stack_service, $this->removed);
        $ports = $workloads->except($removed)
            ->map(fn (NodeWorkload $workload): array => $workload->publishedPorts()->get()->map(fn (NodePublishedPort $port): array => $port->spec())->all())
            ->all();
        foreach ($this->changed as $change) {
            if ($change->publishedPorts !== null) {
                $ports[(string) $change->workload->stack_service] = $change->publishedPorts;
            }
        }
        foreach ($this->added as $service) {
            $ports[$service['service']] = $service['published_ports'];
        }

        return $ports;
    }

    /**
     * The existing services that change: a changed field, or a mount of a shared volume.
     *
     * @return list<string>
     */
    public function changedServices(): array
    {
        return array_values(array_unique([
            ...array_map(fn (NodeStackServiceChange $change): string => (string) $change->workload->stack_service, $this->changed),
            ...array_diff(array_column($this->newMounts, 'service'), array_column($this->added, 'service')),
            ...array_column($this->updatedMounts, 'service'),
            ...array_values($this->removedMounts),
        ]));
    }

    /**
     * @param  ?array<string, mixed>  $previous
     * @param  array<string, mixed>  $next
     */
    private static function change(NodeStack $stack, NodeWorkload $workload, ?array $previous, array $next): NodeStackServiceChange
    {
        // A workload without a previous service (an older stack) takes every value of the new file.
        $take = fn (mixed $current, mixed $previousValue, mixed $nextValue): mixed => $previous === null || self::same($current, $previousValue) ? $nextValue : $current;

        [$variables, $deletedVariables] = self::variables($workload, $previous, $next);

        $builds = $next['build'] !== null;
        $built = $workload->buildsFromGit();
        $previousConfiguration = $previous === null ? [] : NodeStackComposeMapper::revisionConfiguration($previous);
        $nextConfiguration = NodeStackComposeMapper::revisionConfiguration($next);
        $latest = $workload->revisions()->latest('id')->first();
        $revision = null;
        if ($latest !== null) {
            $configuration = $latest->configuration ?? [];
            foreach (self::REVISION_KEYS as $key) {
                $value = $take($configuration[$key] ?? null, $previousConfiguration[$key] ?? null, $nextConfiguration[$key] ?? null);
                if ($value === null) {
                    unset($configuration[$key]);
                } else {
                    $configuration[$key] = $value;
                }
            }
            // A built service keeps its image until the next build, which copies this configuration.
            $image = match (true) {
                $builds => $latest->image,
                $built || $previous === null || $previous['image'] === null => (string) $next['image'],
                default => (string) $take($latest->image, $previous['image'], $next['image']),
            };
            if ($image !== $latest->image || ! self::same($configuration, $latest->configuration ?? [])) {
                $revision = ['image' => $image, 'configuration' => $configuration];
            }
        } elseif (! $builds) {
            $revision = ['image' => (string) $next['image'], 'configuration' => $nextConfiguration];
        }

        $notes = fn (?array $service): ?string => $service === null || $service['notes'] === [] ? null : mb_substr(implode(' ', array_unique($service['notes'])), 0, 255);
        $buildFields = fn (?array $service): array => $service === null || $service['build'] === null ? [] : [
            'base_directory' => $service['build']['context'] === '' ? null : $service['build']['context'],
            'dockerfile_location' => $service['build']['dockerfile'],
            'build_target' => $service['build']['target'],
        ];
        $attributes = [
            'stack_health_check' => $next['health_check'],
            'description' => $take($workload->description, $notes($previous), $notes($next)),
        ];
        if ($builds && ! $built) {
            $attributes = [...$attributes, 'source_type' => NodeWorkloadSourceType::DOCKERFILE, ...self::gitFields($stack), ...$buildFields($next)];
        } elseif (! $builds && $built) {
            $attributes = [
                ...$attributes,
                'source_type' => NodeWorkloadSourceType::IMAGE,
                ...array_fill_keys(['git_repository', 'git_branch', 'github_app_id', 'private_key_id', 'base_directory', 'dockerfile_location', 'build_target'], null),
            ];
        } elseif ($builds) {
            $previousBuild = $buildFields($previous);
            foreach ($buildFields($next) as $key => $value) {
                $attributes[$key] = $previousBuild === [] ? $value : $take($workload->getAttribute($key), $previousBuild[$key], $value);
            }
        }
        $attributes = array_filter($attributes, fn (mixed $value, string $key): bool => ! self::same($workload->getAttribute($key), $value), ARRAY_FILTER_USE_BOTH);

        $routes = fn (array $list): array => array_map(UpdateNodeWorkloadDomains::normalizeRoute(...), array_values($list));
        $currentRoutes = $routes($workload->domain_routes ?? []);
        $nextRoutes = $take($currentRoutes, $routes($previous['routes'] ?? []), $routes($next['routes']));

        // The whole list is one value: a user who changed one port keeps the whole list.
        $currentPorts = $workload->publishedPorts()->get()->map(fn (NodePublishedPort $port): array => $port->spec())->all();
        $nextPorts = $take($currentPorts, $previous['published_ports'] ?? [], $next['published_ports']);

        $stackPaths = array_flip(array_column($next['stack_volumes'], 'path'));
        $ownPaths = $workload->volumes()->whereNull('detached_at')->pluck('mount_path')->flip();
        $volumes = [];
        foreach ($next['volumes'] as $volume) {
            if (! isset($stackPaths[$volume['path']]) && ! $ownPaths->has($volume['path'])) {
                $volumes[$volume['path']] = $volume;
            }
        }
        $nextOwnPaths = array_flip(array_column($next['volumes'], 'path'));
        $keptVolumes = array_values(array_filter(
            array_column($previous['volumes'] ?? [], 'path'),
            fn (string $path): bool => ! isset($nextOwnPaths[$path]) && ! isset($stackPaths[$path]) && $ownPaths->has($path),
        ));

        return new NodeStackServiceChange(
            $workload,
            $previous,
            $next,
            $variables,
            $deletedVariables,
            $revision,
            $attributes,
            self::same($nextRoutes, $currentRoutes) ? null : $nextRoutes,
            array_values($volumes),
            $keptVolumes,
            self::same($nextPorts, $currentPorts) ? null : $nextPorts,
        );
    }

    /**
     * The three-way rule for each environment variable: its value and its runtime and build-time
     * flags. A variable that the user added with a key of the new file, or deleted, stays as it is.
     *
     * @param  ?array<string, mixed>  $previous
     * @param  array<string, mixed>  $next
     * @return array{0: array<string, array{value: string, is_runtime: bool, is_buildtime: bool}>, 1: list<string>}
     */
    private static function variables(NodeWorkload $workload, ?array $previous, array $next): array
    {
        $stored = fn (array $variables): array => array_map(fn (array $variable): array => [...$variable, 'value' => EnvironmentVariable::withoutEdgeWhitespace($variable['value'])], $variables);
        $previousVariables = $previous === null ? null : $stored(NodeStackComposeMapper::environmentVariables($previous));
        $nextVariables = $stored(NodeStackComposeMapper::environmentVariables($next));
        $current = $workload->environment_variables()->get()->keyBy('key')->map(fn (EnvironmentVariable $variable): array => [
            'value' => (string) $variable->value,
            'is_runtime' => (bool) $variable->is_runtime,
            'is_buildtime' => (bool) $variable->is_buildtime,
        ]);

        $set = [];
        $deleted = [];
        foreach (array_keys(($previousVariables ?? []) + $nextVariables) as $key) {
            $currentVariable = $current->get($key);
            $previousVariable = $previous === null ? $currentVariable : ($previousVariables[$key] ?? null);
            $nextVariable = $nextVariables[$key] ?? null;
            if ($currentVariable === null) {
                if ($previousVariable === null && $nextVariable !== null) {
                    $set[$key] = $nextVariable;
                }

                continue;
            }
            if ($previousVariable === null) {
                continue;
            }
            if ($nextVariable === null) {
                if ($currentVariable === $previousVariable) {
                    $deleted[] = (string) $key;
                }

                continue;
            }
            $merged = [];
            foreach ($nextVariable as $field => $value) {
                $merged[$field] = $currentVariable[$field] === $previousVariable[$field] ? $value : $currentVariable[$field];
            }
            if ($merged !== $currentVariable) {
                $set[$key] = $merged;
            }
        }

        return [$set, $deleted];
    }

    /**
     * The mounts of shared volumes. The volume of a key is the volume that the service of the
     * previous file mounts at the path of that key.
     *
     * @param  list<array<string, mixed>>  $previous
     * @param  list<array<string, mixed>>  $next
     * @param  Collection<string, NodeWorkload>  $workloads
     * @return array{0: array<string, NodeVolume>, 1: list<array{service: string, key: string, name: string, path: string, read_only: bool}>, 2: array<int, array{service: string, mount_path: string, read_only: bool}>, 3: array<int, string>}
     */
    private static function mounts(array $previous, array $next, Collection $workloads): array
    {
        $current = $workloads->map(fn (NodeWorkload $workload): Collection => $workload->stackVolumeMounts()->with('volume')->get());
        $nextMounts = NodeStackComposeMapper::stackMounts($next);
        $nextKeys = array_flip(array_column($nextMounts, 'key'));

        $volumes = [];
        foreach (NodeStackComposeMapper::stackMounts($previous) as $mount) {
            if (! isset($nextKeys[$mount['key']]) || isset($volumes[$mount['key']])) {
                continue;
            }
            $volume = $current->get($mount['service'])?->firstWhere('mount_path', $mount['path'])?->volume;
            if ($volume !== null) {
                $volumes[$mount['key']] = $volume;
            }
        }

        $new = [];
        $updated = [];
        $kept = [];
        foreach ($nextMounts as $mount) {
            $workload = $workloads->get($mount['service']);
            if ($workload === null) {
                $new[] = $mount;

                continue;
            }
            if ($workload->volumes()->whereNull('detached_at')->where('mount_path', $mount['path'])->exists()) {
                throw new RuntimeException("The volume at {$mount['path']} of the service {$mount['service']} becomes a volume that services share. Coolify cannot move its data to a shared volume. Change the Compose file back, or delete the service on its page and deploy again.");
            }
            $existing = isset($volumes[$mount['key']]) ? $current->get($mount['service'])?->firstWhere('node_volume_id', $volumes[$mount['key']]->id) : null;
            if ($existing === null) {
                $new[] = $mount;

                continue;
            }
            $kept[$existing->id] = true;
            if ($existing->mount_path !== $mount['path'] || (bool) $existing->read_only !== $mount['read_only']) {
                $updated[$existing->id] = ['service' => $mount['service'], 'mount_path' => $mount['path'], 'read_only' => $mount['read_only']];
            }
        }

        $nextServices = collect($next)->keyBy('service');
        $removed = [];
        foreach ($current as $service => $mounts) {
            $nextService = $nextServices->get($service);
            if ($nextService === null) {
                continue;
            }
            $ownPaths = array_flip(array_column($nextService['volumes'], 'path'));
            foreach ($mounts as $mount) {
                if (isset($kept[$mount->id])) {
                    continue;
                }
                if (isset($ownPaths[$mount->mount_path])) {
                    throw new RuntimeException("The shared volume {$mount->volume?->name} at {$mount->mount_path} of the service {$service} becomes a volume of the service only. Coolify cannot move its data. Change the Compose file back.");
                }
                $removed[$mount->id] = (string) $service;
            }
        }

        return [$volumes, $new, $updated, $removed];
    }

    /**
     * The Node of each added service: the Node of a service or a volume that it shares a volume
     * with, else the Node of the first service of the stack.
     *
     * @param  list<array<string, mixed>>  $added
     * @param  list<array<string, mixed>>  $next
     * @param  Collection<string, NodeWorkload>  $workloads
     * @param  array<string, NodeVolume>  $volumes
     * @return array<string, ?int>
     */
    private static function placements(array $added, array $next, Collection $workloads, array $volumes): array
    {
        $nodeOf = fn (NodeWorkload $workload): ?int => $workload->nodes()->orderBy('nodes.id')->value('nodes.id');
        $first = $workloads->sortBy('id')->first();
        $default = $first === null ? null : $nodeOf($first);
        $mounts = collect(NodeStackComposeMapper::stackMounts($next));

        $placements = [];
        foreach ($added as $service) {
            $node = null;
            foreach ($mounts->where('service', $service['service']) as $mount) {
                $node = ($volumes[$mount['key']] ?? null)?->node_id;
                foreach ($mounts->where('key', $mount['key']) as $other) {
                    $workload = $workloads->get($other['service']);
                    $node ??= $workload === null ? null : $nodeOf($workload);
                }
                if ($node !== null) {
                    break;
                }
            }
            $placements[$service['service']] = $node ?? $default;
        }

        return $placements;
    }

    /**
     * A volume lives on one Node, so every service that mounts it must run there.
     *
     * @param  list<array<string, mixed>>  $next
     * @param  Collection<string, NodeWorkload>  $workloads
     * @param  array<string, NodeVolume>  $volumes
     * @param  array<string, ?int>  $placements
     */
    private static function ensureOneNodePerVolume(array $next, Collection $workloads, array $volumes, array $placements): void
    {
        $nodes = [];
        foreach (NodeStackComposeMapper::stackMounts($next) as $mount) {
            $workload = $workloads->get($mount['service']);
            $node = $workload === null ? ($placements[$mount['service']] ?? null) : $workload->nodes()->orderBy('nodes.id')->value('nodes.id');
            if ($node !== null) {
                $nodes[$mount['key']][$node][] = $mount['service'];
            }
            $volumeNode = ($volumes[$mount['key']] ?? null)?->node_id;
            if ($volumeNode !== null) {
                $nodes[$mount['key']][$volumeNode] ??= [];
            }
        }
        foreach ($nodes as $byNode) {
            if (count($byNode) > 1) {
                $services = implode(', ', array_unique(array_merge(...array_values($byNode))));
                throw new RuntimeException("The services {$services} share a volume, but they run on different servers. A volume lives on one server. Move the services to one server first, or change the Compose file back.");
            }
        }
    }

    /** @return array<string, mixed> */
    private static function gitFields(NodeStack $stack): array
    {
        return [
            'git_repository' => $stack->git_repository,
            'git_branch' => $stack->git_branch,
            'github_app_id' => $stack->github_app_id,
            'private_key_id' => $stack->private_key_id,
        ];
    }

    /** Equal values, with the keys of a map in any order (a JSON column can reorder them). */
    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (array_is_list($a) !== array_is_list($b) || count($a) !== count($b)) {
                return false;
            }
            if (! array_is_list($a)) {
                ksort($a);
                ksort($b);
                if (array_keys($a) !== array_keys($b)) {
                    return false;
                }
            }
            foreach ($a as $key => $value) {
                if (! self::same($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }
}
