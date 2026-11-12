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
        $workload = $move->workload;
        // A later move or placement can bring the application back here; its container has the same name.
        if ($workload === null || $move->revision === null || ! data_get($move->result, 'target_ready')
            || $node->workloads()->whereKey($workload->id)->exists()) {
            $this->record($move, ['fence' => 'not needed']);

            return;
        }

        $names = array_map(fn (NodeContainerSlot $slot): string => $slot->containerName($workload), NodeContainerSlot::cases());
        if (! $node->containers()->whereIn('name', $names)->exists()) {
            NodeVolumeCopy::query()
                ->where('node_id', $node->id)
                ->whereNull('podman_name')
                ->where('state', NodeVolumeCopyState::RETAINED)
                ->whereHas('volume', fn ($volumes) => $volumes->where('node_workload_id', $workload->id))
                ->update(['delete_after' => now()->addDays(MoveNodeWorkloadJob::RETAIN_SOURCE_DAYS)]);
            $this->record($move, ['fence' => 'done', 'fenced_at' => now()->toIso8601String()]);

            return;
        }

        $removal = NodeOperation::query()->where('uuid', data_get($move->result, 'fence_removal_operation_uuid'))->first();
        if ($removal !== null && ! $removal->status->isFinal()) {
            return;
        }
        // CreateLifecycleOperation needs a placement on this server, which the move removed.
        $removal = CreateOperation::run(
            $node,
            'workload.lifecycle.v1',
            "fence:{$node->uuid}:{$workload->uuid}:".Str::uuid(),
            $workload,
            $move->revision,
            ['revision_uuid' => $move->revision->uuid, 'action' => NodeWorkloadAction::REMOVE->value, 'container_name' => $workload->containerNameOn($node)],
        );
        $this->record($move, ['fence' => 'removing', 'fence_removal_operation_uuid' => $removal->uuid]);
        ManageNodeWorkloadJob::dispatch($removal->id);
    }

    /** @param array<string, mixed> $values */
    private function record(NodeOperation $move, array $values): void
    {
        $move->update(['result' => [...($move->refresh()->result ?? []), ...$values]]);
    }
}
