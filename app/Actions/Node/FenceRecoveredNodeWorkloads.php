<?php

namespace App\Actions\Node;

use App\Enums\NodeContainerSlot;
use App\Enums\NodeVolumeCopyState;
use App\Enums\NodeWorkloadAction;
use App\Jobs\ManageNodeWorkloadJob;
use App\Jobs\MoveNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeVolumeCopy;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Fencing after a recovery move (decision 0014). The move did not reach the offline source, and
 * Sentinel starts managed containers again after a reboot, so the old container can run next to
 * the moved application when the server comes back. On each container refresh of the server,
 * this removes that container. Its volumes stay on the server as retained copies for
 * RETAIN_SOURCE_DAYS from now, so the user can still get data that is newer than the backup.
 *
 * Runs after a fresh inventory, once the move ended, and converges: `fence` in the move result
 * goes from `pending` to `removing` (a REMOVE is queued) to `done` once the inventory has no
 * container, or to `not needed` when the move rolled back or the application is placed here again.
 */
class FenceRecoveredNodeWorkloads
{
    use AsAction;

    public function handle(Node $node): void
    {
        $moves = NodeOperation::query()
            ->with(['workload', 'revision'])
            ->where('node_id', $node->id)
            ->where('command_type', 'workload.move.v1')
            ->whereIn('result->fence', ['pending', 'removing'])
            ->orderBy('id')
            ->get();
        foreach ($moves as $move) {
            try {
                $this->fence($node, $move);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function fence(Node $node, NodeOperation $move): void
    {
        // The move writes its own checkpoints into the result until it ends.
        if (! $move->status->isFinal()) {
            return;
        }
        // A recovery move of a volume group (decision 0024) fences every service of the group.
        $group = MoveNodeWorkloadJob::movingGroup($move)->filter(fn (array $member): bool => $member['revision'] !== null)
            // A later move or placement can bring the application back here; its container has the same name.
            ->reject(fn (array $member): bool => $node->workloads()->whereKey($member['workload']->id)->exists());
        if ($move->workload === null || $move->revision === null || ! data_get($move->result, 'target_ready') || $group->isEmpty()) {
            $this->record($move, ['fence' => 'not needed']);

            return;
        }

        $running = $group->filter(fn (array $member): bool => $node->containers()
            ->whereIn('name', array_map(fn (NodeContainerSlot $slot): string => $slot->containerName($member['workload']), NodeContainerSlot::cases()))
            ->exists());
        if ($running->isEmpty()) {
            $ids = $group->pluck('workload.id')->all();
            NodeVolumeCopy::query()
                ->where('node_id', $node->id)
                ->whereNull('podman_name')
                ->where('state', NodeVolumeCopyState::RETAINED)
                ->whereHas('volume', fn ($volumes) => $volumes->where(fn ($query) => $query
                    ->whereIn('node_workload_id', $ids)
                    ->orWhereHas('mounts', fn ($mounts) => $mounts->whereIn('node_workload_id', $ids))))
                ->update(['delete_after' => now()->addDays(MoveNodeWorkloadJob::RETAIN_SOURCE_DAYS)]);
            $this->record($move, ['fence' => 'done', 'fenced_at' => now()->toIso8601String()]);

            return;
        }

        $previous = data_get($move->result, 'fence_removal_operation_uuids') ?? array_filter([data_get($move->result, 'fence_removal_operation_uuid')]);
        if (NodeOperation::query()->whereIn('uuid', $previous)->get()->contains(fn (NodeOperation $removal): bool => ! $removal->status->isFinal())) {
            return;
        }
        $removals = [];
        foreach ($running as ['workload' => $workload, 'revision' => $revision]) {
            // CreateLifecycleOperation needs a placement on this server, which the move removed.
            $removal = CreateOperation::run(
                $node,
                'workload.lifecycle.v1',
                "fence:{$node->uuid}:{$workload->uuid}:".Str::uuid(),
                $workload,
                $revision,
                ['revision_uuid' => $revision->uuid, 'action' => NodeWorkloadAction::REMOVE->value, 'container_name' => $workload->containerNameOn($node)],
            );
            $removals[] = $removal;
        }
        $this->record($move, [
            'fence' => 'removing',
            'fence_removal_operation_uuid' => $removals[0]->uuid,
            'fence_removal_operation_uuids' => array_map(fn (NodeOperation $removal): string => $removal->uuid, $removals),
        ]);
        foreach ($removals as $removal) {
            ManageNodeWorkloadJob::dispatch($removal->id);
        }
    }

    /** @param array<string, mixed> $values */
    private function record(NodeOperation $move, array $values): void
    {
        $move->update(['result' => [...($move->refresh()->result ?? []), ...$values]]);
    }
}
