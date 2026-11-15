<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\EnsureNodeRegistry;
use App\Enums\NodeWorkloadSourceType;
use App\Jobs\DeleteNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeStack;
use App\Models\NodeVolumeMount;
use App\Models\NodeWorkload;
use App\Models\Team;
use App\Models\User;
use App\Services\NodeStack\NodeStackCompose;
use App\Services\NodeStack\NodeStackComposeDiff;
use App\Services\NodeStack\NodeStackComposeMapper;
use App\Services\NodeStack\NodeStackServiceChange;
use Illuminate\Support\Facades\DB;
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
            return null;
        }
        $cluster = NodeCluster::query()->findOrFail($stack->node_cluster_id);
        $yaml = NodeStackCompose::parse($compose);
        $composeDirectory = $stack->composeDirectory();
        $reason = NodeStackCompose::unavailableReason($yaml, $cluster->supportsUnderscoreNames(), $composeDirectory);
        if ($reason !== null) {
            throw new RuntimeException("The Compose file at {$this->shortCommit($commitSha)} is not available on Nodes. {$reason}");
        }
        (new NodeStack(['compose_parsed' => $yaml]))->deployOrder();
        $actor = $this->actor($stack, $requestedBy);
        $registryIsNew = ! $cluster->registry()->exists();

        [$summary, $removed] = DB::transaction(function () use ($stack, $cluster, $compose, $yaml, $composeDirectory, $commitSha, $actor): array {
            $stack = NodeStack::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stack->id);
            $stored = $stack->variables ?? [];
            $variables = CreateClusterStack::stackVariables($cluster, $stack, $yaml, $compose, $stored);
            $previous = NodeStackComposeMapper::services($stack->compose_parsed ?? ['services' => []], $stored, $composeDirectory);
            $next = NodeStackComposeMapper::services($yaml, $variables, $composeDirectory);
            $workloads = NodeWorkload::query()->withoutGlobalScopes()
                ->where('node_stack_id', $stack->id)
                ->whereNotNull('stack_service')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('stack_service');
            $diff = NodeStackComposeDiff::between($stack, $previous, $next, $workloads);

            NodeVolumeMount::query()->whereKey(array_keys($diff->removedMounts))->delete();
            foreach ($diff->updatedMounts as $id => $mount) {
                NodeVolumeMount::query()->whereKey($id)->update(['mount_path' => $mount['mount_path'], 'read_only' => $mount['read_only']]);
            }
            foreach ($diff->changed as $change) {
                $this->applyChange($change, $actor);
            }

            $all = $workloads->all();
            $nodes = [];
            foreach ($diff->added as $service) {
                $placement = $diff->placements[$service['service']] === null ? null : Node::query()->find($diff->placements[$service['service']]);
                $node = CreateClusterDockerImageWorkload::placementNode($cluster, $placement);
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

            return [$summary, $diff->removed];
        });

        foreach ($removed as $workload) {
            DeleteNodeWorkloadJob::dispatch($workload->id, $requestedBy?->id);
        }
        // The next network revision starts zot and BuildKit on the Nodes.
        if ($registryIsNew && $cluster->registry()->exists()) {
            QueueNodeClusterNetworkRevision::run($cluster);
        }

        return $summary;
    }

    private function applyChange(NodeStackServiceChange $change, User $actor): void
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
    }

    /**
     * The user that the domains and the new services are saved for. A push webhook has no user:
     * the Compose file of the repository is trusted like at the creation of the stack, so an
     * owner or admin of the stack team saves them.
     */
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

    private function shortCommit(string $commitSha): string
    {
        return substr($commitSha, 0, 7);
    }
}
