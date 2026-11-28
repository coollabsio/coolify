<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Jobs\NotifyNodeWorkloadStoppedJob;
use App\Jobs\RefreshNodeContainersJob;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadEvent;
use App\Models\NodeWorkloadSetEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Records a status of a workload that Sentinel converges (decision 0026). A status of the revision
 * of a user operation (deploy, rollback, stop, start, removal) updates that operation and its log.
 * A change that Sentinel made alone, such as a start after a crash or a reboot, adds an event to
 * the deployment history instead. Sentinel that cannot start a container again sends the
 * "resource stopped" notification of v4.
 */
class RecordNodeWorkloadStatus
{
    use AsAction;

    /** The newest Sentinel status lines that an operation keeps for its log. */
    private const MAX_LOG_LINES = 50;

    /**
     * @param  array<string, mixed>  $status  `workload_id`, `revision_id`, `operation_id`, `status`, `reason`, `runtime_id`, `autonomous`, `observed_at_unix_ms`, and the `event_id` of an event.
     */
    public function handle(Node $node, array $status, bool $fromEvent = true): void
    {
        $value = (string) ($status['status'] ?? '');
        if (! in_array($value, ['progressing', 'healthy', 'failed', 'stopped', 'removed'], true)) {
            return;
        }
        $workload = $node->workloads()->withoutGlobalScopes()->where('node_workloads.uuid', (string) ($status['workload_id'] ?? ''))->first()
            ?? NodeWorkload::query()->withoutGlobalScopes()->where('team_id', $node->team_id)->where('uuid', (string) ($status['workload_id'] ?? ''))->first();
        if ($workload === null) {
            return;
        }
        $observedAt = isset($status['observed_at_unix_ms']) && (int) $status['observed_at_unix_ms'] > 0
            ? Carbon::createFromTimestampMs((int) $status['observed_at_unix_ms'])
            : now();
        $reason = $workload->redactLogText(mb_substr((string) ($status['reason'] ?? ''), 0, 2000));
        $entry = NodeWorkloadSetEntry::query()->with('revision')->where('node_id', $node->id)->where('node_workload_id', $workload->id)->first();
        $revisionId = (string) ($status['revision_id'] ?? '');
        if ($entry !== null && $entry->revision?->uuid === $revisionId) {
            $entry->forceFill(['status' => $value, 'status_reason' => $reason === '' ? null : $reason, 'status_at' => $observedAt])->saveQuietly();
        }

        $operation = $this->operation($node, $workload, $status, $value);
        if ($operation !== null) {
            $this->updateOperation($operation, $value, $reason, $status, $observedAt);
        } elseif ($fromEvent && ($status['autonomous'] ?? false) && filled($status['event_id'] ?? null)) {
            $event = NodeWorkloadEvent::query()->firstOrCreate(['event_id' => (string) $status['event_id']], [
                'node_id' => $node->id,
                'node_workload_id' => $workload->id,
                'node_workload_revision_id' => $workload->revisions()->where('uuid', $revisionId)->value('id'),
                'status' => $value,
                'reason' => $reason === '' ? null : $reason,
                'observed_at' => $observedAt,
            ]);
            // Sentinel could not start the container again by itself: like a v4 resource that stopped.
            if ($value === 'failed' && $event->wasRecentlyCreated) {
                NotifyNodeWorkloadStoppedJob::dispatch($node->id, $workload->id);
            }
        }
        if (in_array($value, ['healthy', 'failed', 'stopped', 'removed'], true)) {
            RefreshNodeContainersJob::dispatch($node->id, forStatusEvent: true);
        }
    }

    /** @param array<string, mixed> $status */
    private function operation(Node $node, NodeWorkload $workload, array $status, string $value): ?NodeOperation
    {
        $active = [NodeOperationStatus::DISPATCHED, NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING, NodeOperationStatus::UNCERTAIN];
        if ($value === 'removed') {
            // The removed entry has no operation: the active removal of the workload on this Node ends.
            return NodeOperation::query()
                ->where('node_id', $node->id)
                ->where('node_workload_id', $workload->id)
                ->where('command_type', 'workload.lifecycle.v1')
                ->where('request->action', NodeWorkloadAction::REMOVE->value)
                ->whereIn('status', $active)
                ->latest('id')
                ->first();
        }
        $uuid = (string) ($status['operation_id'] ?? '');
        if ($uuid === '' || ($status['autonomous'] ?? false)) {
            return null;
        }

        return NodeOperation::query()
            ->where('node_id', $node->id)
            ->where('node_workload_id', $workload->id)
            ->where('uuid', $uuid)
            ->whereIn('status', $active)
            ->first();
    }

    /** @param array<string, mixed> $status */
    private function updateOperation(NodeOperation $operation, string $value, string $reason, array $status, Carbon $observedAt): void
    {
        DB::transaction(function () use ($operation, $value, $reason, $status, $observedAt): void {
            $operation = NodeOperation::query()->lockForUpdate()->find($operation->id);
            if ($operation === null || $operation->status->isFinal()) {
                return;
            }
            $result = $operation->result ?? [];
            $lines = [...($result['sentinel_events'] ?? []), ['at' => $observedAt->toIso8601String(), 'status' => $value, 'reason' => $reason]];
            $result['sentinel_events'] = array_slice($lines, -self::MAX_LOG_LINES);
            if (filled($status['runtime_id'] ?? null)) {
                $result['runtime_id'] = (string) $status['runtime_id'];
            }
            $action = $operation->command_type === 'workload.lifecycle.v1'
                ? NodeWorkloadAction::tryFrom((string) data_get($operation->request, 'action'))
                : null;
            $done = match ($action) {
                NodeWorkloadAction::STOP => $value === 'stopped',
                NodeWorkloadAction::REMOVE => $value === 'removed',
                default => $value === 'healthy',
            };
            try {
                if ($value === 'failed') {
                    TransitionOperation::run($operation, NodeOperationStatus::FAILED, result: $result, error: $reason === '' ? 'Sentinel could not converge the workload.' : $reason);
                } elseif ($done) {
                    $operation = $this->advance($operation, NodeOperationStatus::VERIFYING, $result);
                    TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
                } elseif ($value === 'progressing') {
                    $operation = $this->advance($operation, filled($status['runtime_id'] ?? null) ? NodeOperationStatus::VERIFYING : NodeOperationStatus::RUNNING, $result);
                    $operation->update(['result' => $result]);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Moves an active operation forward to `RUNNING` or `VERIFYING` through the allowed
     * transitions. An operation that is already there or further stays.
     *
     * @param  array<string, mixed>  $result
     */
    private function advance(NodeOperation $operation, NodeOperationStatus $target, array $result): NodeOperation
    {
        if ($operation->status === NodeOperationStatus::UNCERTAIN) {
            $operation = TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED, result: $result);
        }
        if ($operation->status === NodeOperationStatus::DISPATCHED) {
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING, result: $result);
        }
        if ($target === NodeOperationStatus::VERIFYING && $operation->status === NodeOperationStatus::RUNNING) {
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
        }

        return $operation;
    }
}
