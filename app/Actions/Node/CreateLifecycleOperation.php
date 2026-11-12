<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadDesiredState;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateLifecycleOperation
{
    use AsAction;

    public function handle(
        Node $node,
        NodeWorkloadRevision $revision,
        NodeWorkloadAction $action,
        ?User $requestedBy = null,
        bool $updateDesiredState = true,
        bool $partOfMove = false,
        ?int $stopTimeoutSeconds = null,
    ): NodeOperation {
        $node->ensureCapability('workload.lifecycle.v1');
        if ($stopTimeoutSeconds !== null) {
            if ($stopTimeoutSeconds < 1 || $stopTimeoutSeconds > 600) {
                throw new RuntimeException('The stop timeout must be 1 to 600 seconds.');
            }
            $node->ensureReportedCapability('workload.stop_timeout.v1');
        }

        return DB::transaction(function () use ($node, $revision, $action, $requestedBy, $updateDesiredState, $partOfMove, $stopTimeoutSeconds): NodeOperation {
            $workload = NodeWorkload::query()
                ->whereKey($revision->node_workload_id)
                ->where('team_id', $node->team_id)
                ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($node->id))
                ->lockForUpdate()
                ->first();
            if ($workload === null) {
                throw new RuntimeException('The workload is not assigned to this Node.');
            }
            $revision = NodeWorkloadRevision::query()
                ->whereKey($revision->id)
                ->where('node_workload_id', $workload->id)
                ->firstOrFail();
            if (! $partOfMove) {
                $workload->ensureNotMovingVolumes();
            }
            $active = NodeOperation::query()
                ->where('node_id', $node->id)
                ->where('node_workload_id', $workload->id)
                ->whereIn('command_type', ['workload.deploy.v1', 'workload.lifecycle.v1'])
                ->whereIn('status', [
                    NodeOperationStatus::QUEUED,
                    NodeOperationStatus::DISPATCHED,
                    NodeOperationStatus::RUNNING,
                    NodeOperationStatus::VERIFYING,
                    NodeOperationStatus::UNCERTAIN,
                ])
                ->exists();
            if ($active) {
                throw new RuntimeException('This workload already has an active operation.');
            }
            if (in_array($action, [NodeWorkloadAction::START, NodeWorkloadAction::RESTART], true)) {
                // After a stateful move the old container must not start on the retained copy.
                $elsewhere = $workload->volumes()->with('node')->whereNotNull('node_id')->where('node_id', '!=', $node->id)->first();
                if ($elsewhere !== null) {
                    throw new RuntimeException("The volume {$elsewhere->name} is on server ".($elsewhere->node?->name ?? 'another server').'. Move the application instead.');
                }
            }

            if ($updateDesiredState) {
                $workload->update(['desired_state' => match ($action) {
                    NodeWorkloadAction::START, NodeWorkloadAction::RESTART => NodeWorkloadDesiredState::RUNNING,
                    NodeWorkloadAction::STOP => NodeWorkloadDesiredState::STOPPED,
                    NodeWorkloadAction::REMOVE => NodeWorkloadDesiredState::REMOVED,
                }]);
            }

            return CreateOperation::run(
                $node,
                'workload.lifecycle.v1',
                "lifecycle:{$node->uuid}:{$workload->uuid}:".Str::uuid(),
                $workload,
                $revision,
                [
                    'revision_uuid' => $revision->uuid,
                    'action' => $action->value,
                    'container_name' => $workload->containerNameOn($node),
                    ...($stopTimeoutSeconds === null ? [] : ['stop_timeout_seconds' => $stopTimeoutSeconds]),
                ],
                $requestedBy,
            );
        });
    }
}
