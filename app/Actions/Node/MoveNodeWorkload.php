<?php

namespace App\Actions\Node;

use App\Jobs\MoveNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Moves a cluster application from one of its servers to another server of the same cluster
 * (decision 0014). The user must be allowed to place resources on the target. A move from an
 * offline server is a recovery move: it restores the volumes from their latest backups, which
 * the user must confirm. The caller authorizes `update` on the workload.
 */
class MoveNodeWorkload
{
    use AsAction;

    /**
     * @return array{operation: NodeOperation, source: Node, target: Node, recovery: bool, has_volumes: bool, group: Collection<int, string>}
     *
     * @throws ValidationException `recovery_confirmed` when a recovery move with volumes is not confirmed.
     */
    public function handle(NodeWorkload $workload, string $sourceUuid, string $targetUuid, User $user, bool $recoveryConfirmed = false): array
    {
        $source = $workload->nodes()->where('nodes.uuid', $sourceUuid)->firstOrFail();
        $target = Node::query()
            ->where('uuid', $targetUuid)
            ->where('team_id', $workload->team_id)
            ->where('node_cluster_id', $source->node_cluster_id)
            ->whereDoesntHave('workloads', fn ($query) => $query->whereKey($workload->id))
            ->firstOrFail();
        Gate::forUser($user)->authorize('placeResource', $target);
        $revision = $workload->revisions()->latest('id')->firstOrFail();
        $recovery = NotifyNodeReachability::isOffline($source);
        $hasVolumes = $workload->hasVolumesWithData();
        if ($recovery && $hasVolumes && ! $recoveryConfirmed) {
            throw ValidationException::withMessages(['recovery_confirmed' => 'Confirm that the volumes are restored from their latest backups.']);
        }
        $operation = CreateMoveOperation::run($source, $target, $revision, $user, recovery: $recovery);
        MoveNodeWorkloadJob::dispatch($operation->id);

        return [
            'operation' => $operation,
            'source' => $source,
            'target' => $target,
            'recovery' => $recovery,
            'has_volumes' => $hasVolumes,
            // The services that share a volume with this one move with it (decision 0024).
            'group' => MoveNodeWorkloadJob::movingGroup($operation)->slice(1)->pluck('workload.stack_service')->filter()->values(),
        ];
    }
}
