<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\EnsureNodeRegistry;
use App\Enums\AccessLevel;
use App\Enums\NodeWorkloadSourceType;
use App\Jobs\DeleteNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeStack;
use App\Models\NodeVolumeMount;
use App\Models\NodeWorkload;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\NodeStack\NodeStackCompose;
use App\Services\NodeStack\NodeStackComposeDiff;
use App\Services\NodeStack\NodeStackComposeMapper;
use App\Services\NodeStack\NodeStackServiceChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Makes the services of a stack from Git match a new version of its Compose file (decision 0023,
 * step 2): it adds, changes, and removes services as NodeStackComposeDiff decides. Every check
 * runs before the first write, and the writes are one transaction, so a failure changes nothing.
 * A removed service is deleted after the commit, by the normal delete of a workload.
 */
class SyncNodeStackCompose
{
    use AsAction;

    /**
     * @return ?array{commit_sha: string, synced_at: string, added: list<string>, changed: list<string>, removed: list<string>, kept_volumes: list<string>} The summary, or null when the file did not change.
     *
     * @throws RuntimeException With the reason, when the new file cannot be applied.
     */
    public function handle(NodeStack $stack, string $compose, string $commitSha, ?User $requestedBy = null): ?array
    {
        if (! $stack->hasGitSource()) {
            throw new RuntimeException('Only a stack from Git reads its Compose file again.');
        }
        if ($compose === $stack->compose_raw) {
            $this->deleteRemovedServices($stack, $requestedBy);

            return null;
        }
        $cluster = NodeCluster::query()->findOrFail($stack->node_cluster_id);
        $yaml = NodeStackCompose::parse($compose);
        $composeDirectory = $stack->composeDirectory();
        $reason = NodeStackCompose::unavailableReason($yaml, $cluster->supportsUnderscoreNames(), $composeDirectory, $cluster->supportsPublishedPorts());
        if ($reason !== null) {
            throw new RuntimeException("The Compose file at {$this->shortCommit($commitSha)} is not available on Nodes. {$reason}");
        }
        (new NodeStack(['compose_parsed' => $yaml]))->deployOrder();
        $actor = $this->actor($stack, $requestedBy);
        $registryIsNew = ! $cluster->registry()->exists();

        $portsChanged = false;
        $summary = DB::transaction(function () use ($stack, $cluster, $compose, $yaml, $composeDirectory, $commitSha, $actor, $requestedBy, &$portsChanged): array {
            $stack = NodeStack::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stack->id);
            $stored = $stack->variables ?? [];
            $variables = CreateClusterStack::stackVariables($cluster, $stack, $yaml, $compose, $stored);
            $previousYaml = $stack->compose_parsed ?? ['services' => []];
            $sharedKeys = $this->sharedKeys($stack, [
                NodeStackComposeMapper::services($previousYaml, $stored, $composeDirectory),
                NodeStackComposeMapper::services($yaml, $variables, $composeDirectory),
            ]);
            $previous = NodeStackComposeMapper::services($previousYaml, $stored, $composeDirectory, $sharedKeys);
            $next = NodeStackComposeMapper::services($yaml, $variables, $composeDirectory, $sharedKeys);
            $workloads = NodeWorkload::query()->withoutGlobalScopes()
                ->where('node_stack_id', $stack->id)
                ->whereNotNull('stack_service')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('stack_service');
            $diff = NodeStackComposeDiff::between($stack, $previous, $next, $workloads);
            $portConflict = CreateClusterStack::portConflict($cluster, $diff->publishedPorts($workloads), $workloads->pluck('id')->all());
            if ($portConflict !== null) {
                throw new RuntimeException("The Compose file at {$this->shortCommit($commitSha)} is not available on Nodes. {$portConflict}");
            }
            // A removed service is deleted after the commit; its ports are free at once.
            foreach ($diff->removed as $workload) {
                $portsChanged = $workload->publishedPorts()->delete() > 0 || $portsChanged;
            }
            $portsChanged = $portsChanged
                || collect($diff->changed)->contains(fn (NodeStackServiceChange $change): bool => $change->publishedPorts !== null)
                || collect($diff->added)->contains(fn (array $service): bool => $service['published_ports'] !== []);
            $portsOpened = collect($diff->added)->contains(fn (array $service): bool => $service['published_ports'] !== [])
                || collect($diff->changed)->contains(fn (NodeStackServiceChange $change): bool => filled($change->publishedPorts));
            if ($portsOpened && $requestedBy !== null) {
                try {
                    PublishNodeWorkloadPort::ensureMayOpenOnCluster($cluster, $requestedBy);
                } catch (ValidationException $exception) {
                    throw new RuntimeException("The Compose file at {$this->shortCommit($commitSha)} publishes ports. ".collect($exception->errors())->flatten()->first());
                }
            }

            NodeVolumeMount::query()->whereKey(array_keys($diff->removedMounts))->delete();
            foreach ($diff->updatedMounts as $id => $mount) {
                NodeVolumeMount::query()->whereKey($id)->update(['mount_path' => $mount['mount_path'], 'read_only' => $mount['read_only']]);
            }
            foreach ($diff->changed as $change) {
                $this->applyChange($cluster, $change, $actor);
            }

            $all = $workloads->all();
            $nodes = [];
            foreach ($diff->added as $service) {
                $placement = $diff->placements[$service['service']] === null ? null : Node::query()->find($diff->placements[$service['service']]);
                $node = $this->placementNode($cluster, $workloads, $service['service'], $placement, $service['build'] !== null
                    ? $cluster->builderArchitectures()
                    : ReadImageArchitectures::run((string) $service['image']), $requestedBy);
                $all[$service['service']] = CreateClusterStack::createService($stack, $node, $service, $actor);
                $nodes[$node->id] = $node;
            }
            CreateClusterStack::createStackVolumes($stack, $diff->newMounts, $all, $diff->volumes);
            foreach ($nodes as $node) {
                EnsureNodeWorkloadDnsNames::run($node);
            }
            $builds = collect($diff->added)->contains(fn (array $service): bool => $service['build'] !== null)
                || collect($diff->changed)->contains(fn (NodeStackServiceChange $change): bool => ($change->attributes['source_type'] ?? null) === NodeWorkloadSourceType::DOCKERFILE);
            if ($builds) {
                EnsureNodeRegistry::run($cluster);
            }

            $summary = [
                'commit_sha' => $commitSha,
                'synced_at' => now()->toIso8601String(),
                'added' => array_column($diff->added, 'service'),
                'changed' => $diff->changedServices(),
                'removed' => array_map(fn (NodeWorkload $workload): string => (string) $workload->stack_service, $diff->removed),
                'kept_volumes' => array_merge(...array_map(
                    fn (NodeStackServiceChange $change): array => array_map(fn (string $path): string => "{$change->workload->stack_service}: {$path}", $change->keptVolumes),
                    $diff->changed,
                )),
            ];
            $stack->update([
                'compose_raw' => $compose,
                'compose_parsed' => $yaml,
                'variables' => $variables,
                'compose_changes' => $summary,
            ]);

            return $summary;
        });

        $this->deleteRemovedServices($stack, $requestedBy);
        // The next network revision starts zot and BuildKit on the Nodes, and opens and closes the published ports.
        if (($registryIsNew && $cluster->registry()->exists()) || $portsChanged) {
            QueueNodeClusterNetworkRevision::run($cluster);
        }

        return $summary;
    }

    private function applyChange(NodeCluster $cluster, NodeStackServiceChange $change, User $actor): void
    {
        $workload = $change->workload;
        foreach ($change->variables as $key => $variable) {
            $existing = $workload->environment_variables()->where('key', $key)->first();
            if ($existing === null) {
                $workload->environment_variables()->create(['key' => $key, ...$variable, 'is_literal' => true, 'is_preview' => false]);
            } else {
                $existing->update($variable);
            }
        }
        if ($change->deletedVariables !== []) {
            $workload->environment_variables()->whereIn('key', $change->deletedVariables)->get()->each->delete();
        }
        if ($change->attributes !== []) {
            $workload->update($change->attributes);
        }
        if ($change->revision !== null) {
            $latest = $workload->revisions()->latest('id')->first();
            $workload->createRevision($change->revision['image'], $change->revision['configuration'], $latest?->environment ?? [], $latest?->secrets ?? []);
        }
        if ($change->routes !== null) {
            UpdateNodeWorkloadDomains::run($workload, $change->routes, $actor);
        }
        foreach ($change->volumes as $volume) {
            CreateNodeVolume::run($workload, $volume['name'], $volume['path']);
        }
        if ($change->publishedPorts !== null) {
            PublishNodeWorkloadPort::replace($cluster, $workload, $change->publishedPorts);
        }
    }

    /**
     * The user that the domains and the new services are saved for. A push webhook has no user:
     * the Compose file of the repository is trusted like at the creation of the stack, so an
     * owner or admin of the stack team saves them.
     */
    /**
     * The Node of a new service. A requester without team-wide write may only place it on a Node where
     * placeResource allows them (NodePolicy), the Nodes of the other services of the stack first.
     *
     * @param  Collection<string, NodeWorkload>  $services
     * @param  list<string>|null  $architectures
     */
    private function placementNode(NodeCluster $cluster, Collection $services, string $service, ?Node $placement, ?array $architectures, ?User $requestedBy): Node
    {
        if ($requestedBy === null || app(AccessResolver::class)->allowsTeamWide($requestedBy, AccessLevel::Write, (int) $cluster->team_id)) {
            return CreateClusterDockerImageWorkload::placementNode($cluster, $placement, $architectures);
        }
        $allowed = fn (Node $node): bool => Gate::forUser($requestedBy)->allows('placeResource', $node);
        if ($placement !== null) {
            if (! $allowed($placement)) {
                throw new RuntimeException("The new service {$service} must run on {$placement->name}, where you may not place resources. Ask a team admin to deploy the stack.");
            }

            return CreateClusterDockerImageWorkload::placementNode($cluster, $placement, $architectures);
        }
        $stackNodeIds = $services->flatMap(fn (NodeWorkload $workload) => $workload->nodes()->pluck('nodes.id'))->unique();
        $candidates = $cluster->nodes()->get()
            ->filter($allowed)
            ->sortBy(fn (Node $node): int => $stackNodeIds->contains($node->id) ? 0 : 1)
            ->values();
        foreach ($candidates as $candidate) {
            try {
                return CreateClusterDockerImageWorkload::placementNode($cluster, $candidate, $architectures);
            } catch (RuntimeException|\DomainException) {
                continue;
            }
        }

        throw new RuntimeException("The new service {$service} needs a server where you may place resources (write access on a resource that runs there), and none of them can take it. Ask a team admin to deploy the stack.");
    }

    private function actor(NodeStack $stack, ?User $requestedBy): User
    {
        if ($requestedBy?->isAdminOfTeam($stack->team_id)) {
            return $requestedBy;
        }
        $admin = Team::query()->find($stack->team_id)?->members()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->orderByRaw("CASE WHEN team_user.role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('users.id')
            ->first();
        if (! $admin instanceof User) {
            throw new RuntimeException('The stack team has no owner or admin to apply the changes of the Compose file.');
        }

        return $admin;
    }

    /**
     * The keys of the volumes whose data is in a stack volume already (decision 0024). Such a volume
     * stays a stack volume when only one service still mounts it, so its data is not lost.
     *
     * @param  list<list<array<string, mixed>>>  $mappings  The services of the previous and the next file.
     * @return array<string, true>
     */
    private function sharedKeys(NodeStack $stack, array $mappings): array
    {
        $mounted = NodeVolumeMount::query()
            ->whereHas('volume', fn ($volume) => $volume->where('node_stack_id', $stack->id))
            ->with('workload:id,stack_service')
            ->get()
            ->mapWithKeys(fn (NodeVolumeMount $mount): array => [$mount->workload?->stack_service.'|'.$mount->mount_path => true]);
        $keys = [];
        foreach ($mappings as $services) {
            foreach ($services as $service) {
                foreach ([...$service['volumes'], ...$service['stack_volumes']] as $volume) {
                    if ($mounted->has($service['service'].'|'.$volume['path'])) {
                        $keys[$volume['key']] = true;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Deletes every service of the stack that the stored Compose file does not have. It runs at each
     * deploy, so a delete that failed before is tried again. A delete that runs is not queued twice.
     */
    private function deleteRemovedServices(NodeStack $stack, ?User $requestedBy): void
    {
        $services = array_map('strval', array_keys($stack->refresh()->compose_parsed['services'] ?? []));
        NodeWorkload::query()->withoutGlobalScopes()
            ->where('node_stack_id', $stack->id)
            ->whereNotIn('stack_service', $services)
            ->pluck('id')
            ->each(fn (int $id) => DeleteNodeWorkloadJob::dispatch($id, $requestedBy?->id));
    }

    private function shortCommit(string $commitSha): string
    {
        return substr($commitSha, 0, 7);
    }
}
