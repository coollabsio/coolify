<?php

namespace App\Jobs;

use App\Actions\Node\ApplyNodeClusterNetworkRevision;
use App\Actions\Node\ClaimOperation;
use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\CreateMoveOperation;
use App\Actions\Node\DeleteNodeVolumes;
use App\Actions\Node\EnsureNodeWorkloadAddress;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Actions\Node\ReceiveNodeVolumes;
use App\Actions\Node\RestoreNodeVolume;
use App\Actions\Node\ServeNodeVolumes;
use App\Actions\Node\TransitionOperation;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeVolumeCopyState;
use App\Enums\NodeWorkloadAction;
use App\Exceptions\NodeClusterNetworkNotAppliedException;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeVolume;
use App\Models\NodeVolumeCopy;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Moves a workload to another Node of its cluster: make before break. A workload with ingress
 * routes or a stack first gets the firewall allow for its target address on every reachable Node,
 * and its source keeps serving until ingress and the stack DNS names learned the target.
 *
 * A workload with volumes moves break before make (decision 0007): the data is copied while the
 * source runs, the source stops, a final pass copies the changes, the volumes switch to the
 * target, and the target starts. Checkpoints in the operation result tell cleanUp() what to undo.
 */
class MoveNodeWorkloadJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** How long the reachable Nodes may take to apply the network revision that allows the target. */
    public const NETWORK_WAIT_SECONDS = ApplyNodeClusterNetworkRevision::WAIT_SECONDS;

    /** Longest wait for a target deployment that another worker runs. */
    public const DEPLOYMENT_WAIT_SECONDS = 660;

    /**
     * How long the source keeps serving after the target became ready. The target Sentinel
     * publishes the endpoint to Corrosion at once, Corrosion replicates it to the other Nodes
     * within about a second, and each ingress renderer reads it on its next pass (every 5 s).
     */
    public const INGRESS_SETTLE_SECONDS = 10;

    /** Time kept after the target deployment for the ingress settle and the source removal. */
    public const REMOVAL_RESERVE_SECONDS = 240;

    /** A stateful move copies all data before the downtime, so its worker may run for hours. Below the Horizon worker timeout. */
    public const STATEFUL_TIMEOUT = 21600;

    /** Time kept after the copy for the stop, the network wait, the target deployment, and the source removal. */
    public const STATEFUL_RESERVE_SECONDS = 1800;

    public const TRANSFER_POLL_SECONDS = 2;

    /** How often the copy progress is written to the operation for the UI. */
    public const TRANSFER_PROGRESS_SECONDS = 10;

    /** Attempts of one copy pass. A Sentinel restart fails a running pass; the next attempt sends only what is missing. */
    public const TRANSFER_PASS_ATTEMPTS = 3;

    public const RETAIN_SOURCE_DAYS = 7;

    /** How long the source may take for a clean shutdown before the final copy. Databases flush their data on stop. */
    public const SOURCE_STOP_TIMEOUT_SECONDS = 120;

    public int $tries = 1;

    /** Network wait, an inline target deployment, the ingress settle, and the source removal; below the stale operation limit. */
    public int $timeout = 1080;

    public function __construct(public int $operationId)
    {
        // A stateful move touches its operation while it copies, so the stale operation limit does not apply.
        $operation = NodeOperation::query()->find($operationId);
        if ($operation?->node_workload_id !== null && NodeVolume::query()->where('node_workload_id', $operation->node_workload_id)->whereNotNull('node_id')->exists()) {
            $this->timeout = self::STATEFUL_TIMEOUT;
        }
    }

    public function handle(): void
    {
        $operation = NodeOperation::query()->with(['node', 'workload', 'revision'])->findOrFail($this->operationId);
        if ($operation->status->isFinal()) {
            return;
        }

        $deadline = now()->addSeconds($this->timeout - self::REMOVAL_RESERVE_SECONDS);
        try {
            $target = Node::query()
                ->where('uuid', data_get($operation->request, 'target_node_uuid'))
                ->where('team_id', $operation->node->team_id)
                ->where('node_cluster_id', $operation->node->node_cluster_id)
                ->firstOrFail();
            if (data_get($operation->request, 'restore') !== null && $target->id === $operation->node->id) {
                $this->restoreInPlace($operation, $target);

                return;
            }
            $recovery = self::recoveryBackups($operation);
            $volumes = $recovery === null
                ? CreateMoveOperation::volumesToTransfer($operation->workload, $operation->node, $target)
                : $operation->workload->volumes()->whereNotNull('node_id')->orderBy('id')->get();
            if (($volumes->isNotEmpty() && $this->timeout < self::STATEFUL_TIMEOUT)
                || ($recovery !== null && $volumes->pluck('uuid')->diff(array_keys($recovery))->isNotEmpty())) {
                throw new RuntimeException('The application got a volume after the move was queued. Start the move again.');
            }
            if (ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED) === null) {
                return;
            }
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
            if ($recovery !== null) {
                // Recorded before the target runs, so the old container on the source is removed when it comes back.
                self::checkpoint($operation, ['fence' => 'pending']);
            }
            if ($volumes->isNotEmpty()) {
                self::checkpoint($operation, ['stateful' => true, 'volume_uuids' => $volumes->pluck('uuid')->all()]);
            }

            if (! $target->workloads()->whereKey($operation->workload->id)->exists()) {
                // Recorded first, so cleanup after a killed worker knows that the move owns this placement.
                $operation->update(['result' => [...($operation->result ?? []), 'target_attached' => true]]);
                $target->workloads()->attach($operation->workload);
            }
            $cluster = $target->cluster;
            $routed = $operation->workload->receivesClusterTraffic() && $cluster !== null;
            if ($routed) {
                // Every ingress Node and every peer of its stack must reach the target address before the target serves traffic.
                // DispatchWorkloadDeployment then finds the address and queues no second revision.
                EnsureNodeWorkloadAddress::run($target, $operation->workload);
                $this->waitForNetworkRevision($cluster, $operation->requestedBy);
            }
            if ($volumes->isNotEmpty()) {
                // After the network wait, which needs no data, so the downtime stays short.
                $this->copyVolumes($operation, $target, $volumes, now()->addSeconds($this->timeout - self::STATEFUL_RESERVE_SECONDS));
            }

            $deployment = CreateDeploymentOperation::run($target, $operation->revision, $operation->requestedBy, partOfMove: true);
            if ($deployment['created']) {
                // The deployment waits until the target is healthy, so the source serves until then.
                (new DeployNodeWorkloadJob($deployment['operation']->id, $deadline))->handle();
            }
            $deploymentOperation = $this->waitForDeployment($deployment['operation'], $deadline);
            if ($deploymentOperation->status !== NodeOperationStatus::SUCCEEDED) {
                $verification = data_get($deploymentOperation->result, 'verification', []);
                $targetStarted = data_get($verification, 'state') === 'running';
                if ($volumes->isEmpty() || ! $targetStarted) {
                    throw new RuntimeException('The workload did not become ready on the target server. The source remains active. '.($deploymentOperation->error ?? ''));
                }
                // The volumes are on the target and its container started: going back would lose its writes.
                self::checkpoint($operation, ['target_health' => data_get($verification, 'health') ?? 'not healthy']);
            }
            // The target runs the workload now: cleanup keeps its placement.
            $operation->update(['result' => [...($operation->refresh()->result ?? []), 'target_ready' => true]]);

            if ($routed) {
                Sleep::for(self::INGRESS_SETTLE_SECONDS)->seconds();
            }

            // An offline source gets no command: FenceRecoveredNodeWorkloads removes its container when it comes back.
            $removalOperation = $recovery !== null ? null : CreateLifecycleOperation::run(
                $operation->node,
                $operation->revision,
                NodeWorkloadAction::REMOVE,
                $operation->requestedBy,
                updateDesiredState: false,
                partOfMove: true,
            );
            if ($removalOperation !== null) {
                (new ManageNodeWorkloadJob($removalOperation->id))->handle();
                $removalOperation->refresh();
            }
            if ($removalOperation !== null && $removalOperation->status !== NodeOperationStatus::SUCCEEDED) {
                if ($volumes->isEmpty()) {
                    throw new RuntimeException('The source workload could not be removed after the target became ready.');
                }
                // The data is on the target now, so the source placement must go anyway. The stopped
                // container stays on the source; the START guard keeps it from running on the old copy.
                self::checkpoint($operation, ['source_removal' => 'failed: '.mb_substr((string) $removalOperation->error, 0, 500)]);
            }

            $operation->node->workloads()->detach($operation->workload);
            if ($operation->workload->receivesClusterTraffic() && $operation->node->cluster !== null) {
                // The source container IP is gone: drop its firewall allow on every Node.
                QueueNodeClusterNetworkRevision::run($operation->node->cluster, $operation->requestedBy);
            }
            if ($volumes->isNotEmpty()) {
                self::stopTransferService($operation);
            }
            $result = [
                ...($operation->refresh()->result ?? []),
                'target_node_uuid' => $target->uuid,
                'deployment_operation_uuid' => $deploymentOperation->uuid,
                'removal_operation_uuid' => $removalOperation?->uuid,
            ];
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
        } catch (Throwable $exception) {
            self::cleanUp($operation);
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage().self::rollbackSummary($operation), 0, 2000));
            }
        }
    }

    /** A restore of a volume backup on the server of the volume (decision 0009). */
    private function restoreInPlace(NodeOperation $operation, Node $target): void
    {
        if (ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED) === null) {
            return;
        }
        $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
        self::checkpoint($operation, ['stateful' => true]);
        RestoreNodeVolume::run($operation, now()->addSeconds($this->timeout - self::STATEFUL_RESERVE_SECONDS));
        $result = [...($operation->refresh()->result ?? []), 'target_node_uuid' => $target->uuid];
        $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
        TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
    }

    /**
     * Copies the volumes to the target and switches them there: serve on the source, warm pass,
     * stop the source, final pass, switch. A restore (decision 0009) downloads the restored
     * volume from S3 on the target instead and copies only the other volumes. Each step writes a checkpoint first, so cleanUp()
     * knows what to undo after any failure.
     *
     * @param  Collection<int, NodeVolume>  $volumes
     */
    private function copyVolumes(NodeOperation $operation, Node $target, Collection $volumes, Carbon $deadline): void
    {
        $source = $operation->node;
        $recovery = self::recoveryBackups($operation);
        $restored = $volumes->firstWhere('uuid', data_get($operation->request, 'restore.volume_uuid'));
        $names = $recovery !== null ? [] : $volumes->reject(fn (NodeVolume $volume): bool => $volume->is($restored))
            ->map(fn (NodeVolume $volume): string => $volume->podmanName())->values()->all();
        $secret = self::transferSecret($operation);

        $service = null;
        if ($names !== []) {
            self::checkpoint($operation, ['transfer_served' => true]);
            $service = ServeNodeVolumes::run($source, $operation->uuid, $names, (string) $target->wireguard_ip, $secret, now()->addSeconds($this->timeout));

            $required = array_sum(array_column($service['volumes'], 'used_bytes'));
            $available = data_get($target->fresh()?->metadata, 'disk_available_bytes');
            // 10% headroom: the reported free disk is from the last inventory, and the copy adds metadata.
            if (is_numeric($available) && (int) $available < (int) ceil($required * 1.1)) {
                throw new RuntimeException("Server {$target->name} has ".formatBytes((int) $available).' of free disk, but the volumes use '
                    .formatBytes($required).'. The source remains active.');
            }
        }

        // Recorded before the first pass writes to the target, so cleanup deletes the partial data.
        DB::transaction(function () use ($operation, $volumes, $target): void {
            NodeVolume::query()->whereKey($volumes->modelKeys())->lockForUpdate()->get();
            foreach ($volumes as $volume) {
                NodeVolumeCopy::query()->updateOrCreate(
                    ['node_volume_id' => $volume->id, 'node_id' => $target->id, 'podman_name' => null],
                    ['state' => NodeVolumeCopyState::RECEIVING, 'delete_after' => null],
                );
            }
            self::checkpoint($operation, ['receive_started' => true]);
        });
        if ($service !== null) {
            $this->runPass($operation, $target, 'warm', $service, $secret, $names, $deadline);
        }
        if ($recovery !== null) {
            foreach ($volumes as $volume) {
                $execution = RestoreNodeVolume::executions($volume)->where('uuid', $recovery[$volume->uuid])->firstOrFail();
                // One restore ID for each volume: Sentinel keys its download state by it.
                RestoreNodeVolume::download($target, $operation, $volume->podmanName(), $execution, $deadline, substr(hash('sha256', $operation->uuid.':'.$volume->uuid), 0, 32));
            }
        } elseif ($restored !== null) {
            RestoreNodeVolume::download($target, $operation, $restored->podmanName(), RestoreNodeVolume::target($operation)['execution'], $deadline);
        }

        // Downtime starts. A fresh inventory decides whether a container runs; a stale one could miss writes.
        // The offline source of a recovery gets no command; fencing removes its container later.
        if ($recovery === null) {
            FetchContainers::run($source);
        }
        $sourceRunning = $recovery === null && $source->containers()
            ->where('node_workload_id', $operation->workload->id)
            ->where('is_managed', true)
            ->whereNotIn('state', ['exited', 'stopped', 'created', 'configured', 'dead'])
            ->exists();
        if ($sourceRunning) {
            self::checkpoint($operation, ['source_stopped' => true]);
            $stop = CreateLifecycleOperation::run($source, $operation->revision, NodeWorkloadAction::STOP, $operation->requestedBy, updateDesiredState: false, partOfMove: true, stopTimeoutSeconds: self::SOURCE_STOP_TIMEOUT_SECONDS);
            (new ManageNodeWorkloadJob($stop->id))->handle();
            if ($stop->refresh()->status !== NodeOperationStatus::SUCCEEDED) {
                throw new RuntimeException('The source workload could not be stopped for the final copy. '.($stop->error ?? ''));
            }
        }

        if ($service !== null) {
            $this->runPass($operation, $target, 'final', $service, $secret, $names, $deadline);
            // Compares every file by checksum while the source is still stopped, so the switch happens only to an exact copy.
            $this->runPass($operation, $target, 'verify', $service, $secret, $names, $deadline);
        }

        DB::transaction(function () use ($operation, $volumes, $source, $target): void {
            $locked = NodeVolume::query()->whereKey($volumes->modelKeys())->lockForUpdate()->get();
            $changed = $locked->first(fn (NodeVolume $volume): bool => $volume->node_id !== $source->id);
            if ($changed !== null || $locked->count() !== $volumes->count()) {
                throw new RuntimeException('A volume changed during the move. The source starts again.');
            }
            NodeVolume::query()->whereKey($locked->modelKeys())->update(['node_id' => $target->id]);
            NodeVolumeCopy::query()->whereIn('node_volume_id', $locked->modelKeys())->whereNull('podman_name')->where('node_id', $target->id)->delete();
            foreach ($locked as $volume) {
                NodeVolumeCopy::query()->updateOrCreate(
                    ['node_volume_id' => $volume->id, 'node_id' => $source->id, 'podman_name' => null],
                    ['state' => NodeVolumeCopyState::RETAINED, 'delete_after' => now()->addDays(self::RETAIN_SOURCE_DAYS)],
                );
            }
            self::checkpoint($operation, ['switched' => true]);
        });
    }

    /**
     * Runs one copy pass on the target and polls it. Every poll touches the operation, so stale
     * operation recovery does not take over a long copy.
     *
     * @param  'warm'|'final'|'verify'  $pass
     * @param  array{address: string, port: int}  $service
     * @param  list<string>  $names
     */
    private function runPass(NodeOperation $operation, Node $target, string $pass, array $service, string $secret, array $names, Carbon $deadline): void
    {
        $attempt = 1;
        $connectionErrors = 0;
        $progressWrittenAt = null;
        while (true) {
            if (now()->gte($deadline)) {
                throw new RuntimeException("The {$pass} copy of the volumes did not finish in time.");
            }
            try {
                $state = ReceiveNodeVolumes::run($target, $operation->uuid, $pass, $service['address'], $service['port'], $secret, $names);
                $connectionErrors = 0;
            } catch (ConnectionException $exception) {
                if (++$connectionErrors >= 5) {
                    throw $exception;
                }
                Sleep::for(self::TRANSFER_POLL_SECONDS)->seconds();

                continue;
            }
            $operation->touch();
            if ($state['state'] !== 'running' || $progressWrittenAt === null || $progressWrittenAt->lte(now()->subSeconds(self::TRANSFER_PROGRESS_SECONDS))) {
                // One entry per pass, so the final pass does not hide the size of the warm copy.
                self::checkpoint($operation, ['transfers' => [
                    ...(data_get($operation->result, 'transfers') ?? []),
                    $pass => [
                        'state' => $state['state'],
                        'bytes_done' => $state['bytes_done'],
                        'bytes_total' => $state['bytes_total'],
                        'attempt' => $attempt,
                    ],
                ]]);
                $progressWrittenAt = now();
            }
            if ($state['state'] === 'succeeded') {
                return;
            }
            if ($state['state'] === 'failed') {
                // A difference does not go away when the check runs again.
                if ($pass === 'verify') {
                    throw new RuntimeException('The copy of the volumes does not match the source: '.($state['error'] !== '' ? $state['error'] : 'Sentinel reported no reason.'));
                }
                if ($attempt >= self::TRANSFER_PASS_ATTEMPTS) {
                    throw new RuntimeException("The {$pass} copy of the volumes failed: ".($state['error'] !== '' ? $state['error'] : 'Sentinel reported no reason.'));
                }
                // The next identical call starts the pass again.
                $attempt++;
            }
            Sleep::for(self::TRANSFER_POLL_SECONDS)->seconds();
        }
    }

    /**
     * Undoes a failed move from its checkpoints. Runs from the job, `failed()`, and stale operation
     * recovery. A move without volumes only releases the target. A stateful move that did not
     * reach the target deployment rolls back: target container removed, volumes back on the
     * source, target data deleted, source started. With `$queue`, the stateful rollback runs in
     * CleanUpNodeWorkloadMoveJob, which also fails the operation at the end; the operation stays
     * active until then, so the reconciler does not act on half-moved volumes.
     */
    public static function cleanUp(NodeOperation $operation, bool $queue = false): void
    {
        $operation->refresh();
        if (! data_get($operation->result, 'stateful')) {
            self::releaseTarget($operation);

            return;
        }
        if ($queue) {
            CleanUpNodeWorkloadMoveJob::dispatch($operation->id);

            return;
        }
        if (data_get($operation->result, 'cleaned_up')) {
            return;
        }
        if (data_get($operation->result, 'restore_in_place')) {
            RestoreNodeVolume::cleanUp($operation);

            return;
        }

        self::stopTransferService($operation);
        if (data_get($operation->refresh()->result, 'target_ready')) {
            // The target runs on the data already: nothing to roll back. The source placement goes,
            // so no reconciler starts the old container on the retained copy.
            if ($operation->workload !== null && $operation->node->workloads()->detach($operation->workload) > 0
                && $operation->workload->receivesClusterTraffic() && $operation->node->cluster !== null) {
                QueueNodeClusterNetworkRevision::run($operation->node->cluster, $operation->requestedBy);
            }
            self::checkpoint($operation, ['cleaned_up' => true]);

            return;
        }

        $result = $operation->result ?? [];
        $source = $operation->node;
        $target = Node::query()
            ->where('uuid', data_get($operation->request, 'target_node_uuid'))
            ->where('team_id', $source->team_id)
            ->first();
        $volumeIds = NodeVolume::query()
            ->where('node_workload_id', $operation->node_workload_id)
            ->whereIn('uuid', data_get($result, 'volume_uuids', []))
            ->pluck('id')
            ->all();
        $names = array_map(fn (string $uuid): string => NodeVolume::PODMAN_PREFIX.$uuid, data_get($result, 'volume_uuids', []));
        $notes = [];

        // 1. The target container must be gone before its volumes can be deleted.
        $targetClean = true;
        if ($target !== null && $operation->workload !== null && $operation->revision !== null
            && data_get($result, 'target_attached') && self::targetDeployed($operation, $target)
            && $target->workloads()->whereKey($operation->workload->id)->exists()) {
            try {
                $removal = CreateLifecycleOperation::run($target, $operation->revision, NodeWorkloadAction::REMOVE, $operation->requestedBy, updateDesiredState: false, partOfMove: true);
                (new ManageNodeWorkloadJob($removal->id))->handle();
                $targetClean = $removal->refresh()->status === NodeOperationStatus::SUCCEEDED;
                $notes['target_removal'] = $targetClean ? 'removed' : 'failed: '.mb_substr((string) $removal->error, 0, 500);
            } catch (Throwable $exception) {
                $targetClean = false;
                $notes['target_removal'] = 'failed: '.mb_substr($exception->getMessage(), 0, 500);
            }
            self::checkpoint($operation, ['target_removed' => $targetClean]);
        }

        // 2. The source is the authority again.
        if (data_get($result, 'switched') && $target !== null) {
            DB::transaction(function () use ($operation, $volumeIds, $source, $target): void {
                NodeVolume::query()->whereKey($volumeIds)->lockForUpdate()->get();
                NodeVolume::query()->whereKey($volumeIds)->where('node_id', $target->id)->update(['node_id' => $source->id]);
                NodeVolumeCopy::query()->whereIn('node_volume_id', $volumeIds)->whereNull('podman_name')->where('node_id', $source->id)->delete();
                foreach ($volumeIds as $volumeId) {
                    NodeVolumeCopy::query()->updateOrCreate(
                        ['node_volume_id' => $volumeId, 'node_id' => $target->id, 'podman_name' => null],
                        ['state' => NodeVolumeCopyState::RECEIVING, 'delete_after' => null],
                    );
                }
                self::checkpoint($operation, ['switched_back' => true]);
            });
        }

        // 3. Delete the partial target data. When that is not possible now, the copies become
        // retained copies that expire at once, so the hourly cleanup deletes them later.
        if ($target !== null && data_get($result, 'receive_started')) {
            $copies = NodeVolumeCopy::query()->whereIn('node_volume_id', $volumeIds)->whereNull('podman_name')->where('node_id', $target->id)->where('state', NodeVolumeCopyState::RECEIVING);
            try {
                if (! $targetClean) {
                    throw new RuntimeException('the target container was not removed');
                }
                DeleteNodeVolumes::run($target, $names);
                $copies->delete();
                $notes['target_volumes'] = 'deleted';
            } catch (Throwable $exception) {
                $copies->update(['state' => NodeVolumeCopyState::RETAINED, 'delete_after' => now()]);
                $notes['target_volumes'] = 'kept for the hourly cleanup: '.mb_substr($exception->getMessage(), 0, 500);
            }
        }

        // 4. Start the source again if the move stopped it.
        if (data_get($result, 'source_stopped') && $operation->workload !== null && $operation->revision !== null) {
            try {
                $start = CreateLifecycleOperation::run($source, $operation->revision, NodeWorkloadAction::START, $operation->requestedBy, updateDesiredState: false, partOfMove: true);
                (new ManageNodeWorkloadJob($start->id))->handle();
                $notes['source_start'] = $start->refresh()->status->value;
            } catch (Throwable $exception) {
                $notes['source_start'] = 'failed: '.mb_substr($exception->getMessage(), 0, 500);
            }
        }

        self::checkpoint($operation, ['rollback' => $notes]);
        self::releaseTarget($operation);
        self::checkpoint($operation, ['cleaned_up' => true]);
    }

    /**
     * The backup execution UUID of each volume, keyed by volume UUID, when the move is a recovery
     * from an offline server (decision 0014); null for a move that copies from the source.
     *
     * @return array<string, string>|null
     */
    public static function recoveryBackups(NodeOperation $operation): ?array
    {
        $recovery = data_get($operation->request, 'recovery');

        return is_array($recovery) ? $recovery : null;
    }

    /** The text that a failed stateful move adds to its error after cleanUp(). */
    public static function rollbackSummary(NodeOperation $operation): string
    {
        $result = $operation->refresh()->result ?? [];
        if (! data_get($result, 'stateful') || data_get($result, 'target_ready')) {
            return '';
        }
        if (data_get($result, 'restore_in_place')) {
            return str_starts_with((string) data_get($result, 'rollback.switch_back', 'done'), 'failed')
                ? ' The volume data could not be switched back. The application stays stopped; both copies are kept.'
                : ' The volume keeps its data from before the restore.';
        }

        return match (true) {
            str_starts_with((string) data_get($result, 'rollback.source_start', 'succeeded'), 'failed') => ' The volumes stay on the source server, but the application could not be started there. Start it again.',
            (bool) data_get($result, 'source_stopped') => ' The volumes stay on the source server, and the application was started there again.',
            default => ' The volumes stay on the source server.',
        };
    }

    /**
     * The one-time rsync secret of a transfer. It is derived from the operation, so a retry or the
     * cleanup gets the same value without storing it.
     */
    public static function transferSecret(NodeOperation $operation): string
    {
        return substr(hash_hmac('sha256', $operation->uuid, (string) config('app.key')), 0, 64);
    }

    private static function stopTransferService(NodeOperation $operation): void
    {
        if (! data_get($operation->refresh()->result, 'transfer_served') || data_get($operation->result, 'transfer_stopped')) {
            return;
        }
        try {
            ServeNodeVolumes::make()->stop($operation->node, $operation->uuid);
            self::checkpoint($operation, ['transfer_stopped' => true]);
        } catch (Throwable $exception) {
            // Sentinel also stops the service at its expiry and when it starts.
            self::checkpoint($operation, ['transfer_stop' => 'failed: '.mb_substr($exception->getMessage(), 0, 500)]);
        }
    }

    /** @param array<string, mixed> $values */
    private static function checkpoint(NodeOperation $operation, array $values): void
    {
        $operation->update(['result' => [...($operation->refresh()->result ?? []), ...$values]]);
    }

    /**
     * Publishes a network revision and waits until every Node that it was queued for applied it,
     * up to the ingress step. Nodes that go offline meanwhile are no longer waited for.
     */
    private function waitForNetworkRevision(NodeCluster $cluster, ?User $user): void
    {
        try {
            ApplyNodeClusterNetworkRevision::run($cluster, $user);
        } catch (NodeClusterNetworkNotAppliedException $exception) {
            $failed = $exception->failedNode;
            throw new RuntimeException($failed !== null
                ? "Server {$failed->name} could not apply the network for the target: {$failed->network_error} The source remains active."
                : 'The cluster network did not allow the target within '.self::NETWORK_WAIT_SECONDS.' seconds. Waiting for: '
                    .implode(', ', $exception->pendingNames).'. The source remains active.');
        }
    }

    /**
     * Waits for a target deployment that another worker runs, for example a deploy that was
     * already active on the target. An uncertain deployment has no worker left, so the wait
     * ends at once; otherwise it ends at the deadline, before the job timeout.
     */
    private function waitForDeployment(NodeOperation $deployment, Carbon $deadline): NodeOperation
    {
        for ($poll = 0; $poll < self::DEPLOYMENT_WAIT_SECONDS && now()->lt($deadline); $poll++) {
            $deployment->refresh();
            if (! $deployment->status->isActive()) {
                return $deployment;
            }
            Sleep::for(1)->second();
        }

        return $deployment->refresh();
    }

    /**
     * Detaches the target placement that the move attached before the target became ready, so
     * the reconciler does not deploy the workload on both servers. A container that the move
     * already deployed there gets a REMOVE first. Runs from the job, `failed()`, and stale
     * operation recovery.
     */
    public static function releaseTarget(NodeOperation $operation): void
    {
        $operation->refresh();
        if (! data_get($operation->result, 'target_attached') || data_get($operation->result, 'target_ready') || $operation->workload === null) {
            return;
        }
        $target = Node::query()
            ->where('uuid', data_get($operation->request, 'target_node_uuid'))
            ->where('team_id', $operation->node->team_id)
            ->first();
        if ($target === null) {
            return;
        }
        if (! data_get($operation->result, 'target_removed') && $target->workloads()->whereKey($operation->workload->id)->exists()) {
            self::queueTargetRemoval($operation, $target);
        }
        if ($target->workloads()->detach($operation->workload) === 0) {
            return;
        }
        if ($operation->workload->receivesClusterTraffic() && $target->cluster !== null) {
            // Withdraw the firewall allow for the address that the target no longer uses.
            QueueNodeClusterNetworkRevision::run($target->cluster, $operation->requestedBy);
        }
    }

    /**
     * Queues a REMOVE of the target container while the target is still assigned, because
     * CreateLifecycleOperation needs the assignment and no reconciler manages the container
     * after the detach. The lifecycle job sends the command with the container name, so it
     * still runs after the detach. When the Node is offline or rejects the operation, the
     * target is detached anyway and the outcome is recorded on the move.
     */
    private static function queueTargetRemoval(NodeOperation $operation, Node $target): void
    {
        if (! self::targetDeployed($operation, $target) || $operation->revision === null) {
            return;
        }

        $outcome = ['target_removal' => 'skipped: the target server is offline'];
        if ($target->is_usable && $target->hasRecentFluxHeartbeat()) {
            try {
                $removal = CreateLifecycleOperation::run(
                    $target,
                    $operation->revision,
                    NodeWorkloadAction::REMOVE,
                    $operation->requestedBy,
                    updateDesiredState: false,
                    partOfMove: true,
                );
                ManageNodeWorkloadJob::dispatch($removal->id);
                $outcome = ['target_removal' => 'queued', 'target_removal_operation_uuid' => $removal->uuid];
            } catch (Throwable $exception) {
                $outcome = ['target_removal' => 'failed: '.mb_substr($exception->getMessage(), 0, 500)];
            }
        }
        $operation->update(['result' => [...($operation->refresh()->result ?? []), ...$outcome]]);
    }

    /** Whether the move may have created a target container: a deploy since the move started, or a container in the inventory. */
    private static function targetDeployed(NodeOperation $operation, Node $target): bool
    {
        return NodeOperation::query()
            ->where('node_id', $target->id)
            ->where('node_workload_id', $operation->workload->id)
            ->where('command_type', 'workload.deploy.v1')
            ->where('created_at', '>=', $operation->created_at)
            ->exists()
            || $target->containers()->where('node_workload_id', $operation->workload->id)->where('is_managed', true)->exists();
    }

    public function failed(?Throwable $exception): void
    {
        $operation = NodeOperation::query()->find($this->operationId);
        if ($operation === null) {
            return;
        }
        if (data_get($operation->result, 'stateful') && ! $operation->status->isFinal()) {
            // The rollback runs in its own worker and fails the operation when it is done.
            self::cleanUp($operation, queue: true);

            return;
        }
        self::cleanUp($operation);
        if (! $operation->refresh()->status->isFinal()) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'The workload move worker stopped before completion.');
        }
    }
}
