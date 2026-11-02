<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateMoveOperation
{
    use AsAction;

    public function handle(Node $source, Node $target, NodeWorkloadRevision $revision, ?User $requestedBy = null): NodeOperation
    {
        $source->refresh();
        $target->refresh();
        $source->ensureCapability('workload.lifecycle.v1');
        $target->ensureCapability('workload.deploy.v1');

        return DB::transaction(function () use ($source, $target, $revision, $requestedBy): NodeOperation {
            if ($source->id === $target->id
                || $source->team_id !== $target->team_id
                || $source->node_cluster_id === null
                || $source->node_cluster_id !== $target->node_cluster_id
                || ! $target->role->runsWorkloads()) {
                throw new RuntimeException('Select another server in the same mesh.');
            }

            $workload = NodeWorkload::query()
                ->whereKey($revision->node_workload_id)
                ->where('team_id', $source->team_id)
                ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($source->id))
                ->lockForUpdate()
                ->firstOrFail();
            $revision = NodeWorkloadRevision::query()
                ->whereKey($revision->id)
                ->where('node_workload_id', $workload->id)
                ->firstOrFail();
            $hasActiveMove = NodeOperation::query()
                ->where('node_workload_id', $workload->id)
                ->where('command_type', 'workload.move.v1')
                ->whereIn('status', [
                    NodeOperationStatus::QUEUED,
                    NodeOperationStatus::DISPATCHED,
                    NodeOperationStatus::RUNNING,
                    NodeOperationStatus::VERIFYING,
                    NodeOperationStatus::UNCERTAIN,
                ])
                ->exists();
            if ($hasActiveMove) {
                throw new RuntimeException('This workload already has an active move.');
            }
            self::volumesToTransfer($workload, $source, $target);

            return CreateOperation::run(
                $source,
                'workload.move.v1',
                "move:{$source->uuid}:{$target->uuid}:{$workload->uuid}:".Str::uuid(),
                $workload,
                $revision,
                ['revision_uuid' => $revision->uuid, 'target_node_uuid' => $target->uuid],
                $requestedBy,
            );
        });
    }

    /**
     * The volumes that a stateful move copies from the source: every volume with data on the
     * source. Throws when a volume is on another Node or a Node cannot take part in the copy.
     *
     * @return Collection<int, NodeVolume>
     */
    public static function volumesToTransfer(NodeWorkload $workload, Node $source, Node $target): Collection
    {
        $volumes = $workload->volumes()->with('node')->whereNotNull('node_id')->orderBy('id')->get();
        $elsewhere = $volumes->first(fn (NodeVolume $volume): bool => $volume->node_id !== $source->id);
        if ($elsewhere !== null) {
            throw new RuntimeException("The volume {$elsewhere->name} is on server ".($elsewhere->node?->name ?? 'another server').", not on {$source->name}.");
        }
        if ($volumes->isEmpty()) {
            return $volumes;
        }
        foreach (['volume.serve.v1', 'volume.delete.v1', 'volume.transfer.v1'] as $capability) {
            $source->ensureReportedCapability($capability);
        }
        foreach (['volume.receive.v1', 'volume.mounts.v1', 'volume.delete.v1', 'volume.transfer.v1'] as $capability) {
            $target->ensureReportedCapability($capability);
        }
        if (blank($target->wireguard_ip)) {
            throw new RuntimeException("Server {$target->name} has no mesh address yet. Wait until the cluster network is ready.");
        }

        return $volumes;
    }
}
