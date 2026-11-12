<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeRegistry;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateMoveOperation
{
    use AsAction;

    /**
     * @param  array{volume_uuid: string, execution_uuid: string}|null  $restore  A restore of a volume backup
     *                                                                            (decision 0009): the volume comes from S3, also to the same server.
     * @param  bool  $recovery  A recovery move from an offline server (decision 0014): nothing runs on the source, and each
     *                          volume comes from its latest backup. The source container is removed when the server comes back.
     */
    public function handle(Node $source, Node $target, NodeWorkloadRevision $revision, ?User $requestedBy = null, ?array $restore = null, bool $recovery = false): NodeOperation
    {
        $source->refresh();
        $target->refresh();
        $source->ensureCapability('workload.lifecycle.v1');
        $target->ensureCapability('workload.deploy.v1');
        if ($source->id !== $target->id && $recovery !== NotifyNodeReachability::isOffline($source)) {
            throw new RuntimeException($recovery
                ? "Server {$source->name} is online again. Move the application normally, so that Coolify copies its current data."
                : "Server {$source->name} is offline. Move the application from its page: Coolify restores its volumes from their latest backups on the target server.");
        }

        return DB::transaction(function () use ($source, $target, $revision, $requestedBy, $restore, $recovery): NodeOperation {
            if (($source->id === $target->id && $restore === null)
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
            if (NodeRegistry::isLocalImage($revision->image)) {
                throw new RuntimeException('The image of this application is only on '.$source->name.'. Deploy the application again, so that it is stored in the cluster registry, then move it.');
            }
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
            $recoveryBackups = null;
            if ($recovery) {
                $recoveryBackups = array_map(fn (ScheduledVolumeBackupExecution $execution): string => $execution->uuid, self::recoveryBackups($workload, $source, $target, $restore));
            } elseif ($source->id !== $target->id) {
                self::volumesToTransfer($workload, $source, $target);
            }

            return CreateOperation::run(
                $source,
                'workload.move.v1',
                ($restore === null ? 'move' : 'restore').":{$source->uuid}:{$target->uuid}:{$workload->uuid}:".Str::uuid(),
                $workload,
                $revision,
                [
                    'revision_uuid' => $revision->uuid,
                    'target_node_uuid' => $target->uuid,
                    ...($restore === null ? [] : ['restore' => $restore]),
                    ...($recoveryBackups === null ? [] : ['recovery' => $recoveryBackups]),
                ],
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
        $volumes = self::volumesOn($workload, $source);
        if ($volumes->isEmpty()) {
            return $volumes;
        }
        foreach (['volume.serve.v1', 'volume.delete.v1', 'volume.transfer.v1', 'workload.stop_timeout.v1'] as $capability) {
            $source->ensureReportedCapability($capability);
        }
        foreach (['volume.receive.v1', 'volume.mounts.v1', 'volume.delete.v1', 'volume.transfer.v1', 'volume.verify.v1'] as $capability) {
            $target->ensureReportedCapability($capability);
        }
        if (blank($target->wireguard_ip)) {
            throw new RuntimeException("Server {$target->name} has no mesh address yet. Wait until the cluster network is ready.");
        }

        return $volumes;
    }

    /**
     * The backup that a recovery move restores for each volume with data, keyed by volume UUID:
     * the latest successful one, or the one that the user selected for `$restore`. Throws when a
     * volume has no backup, because the data on the offline server cannot be copied.
     *
     * @param  array{volume_uuid: string, execution_uuid: string}|null  $restore
     * @return array<string, ScheduledVolumeBackupExecution>
     */
    public static function recoveryBackups(NodeWorkload $workload, Node $source, Node $target, ?array $restore = null): array
    {
        $volumes = self::volumesOn($workload, $source);
        if ($volumes->isEmpty()) {
            return [];
        }
        BackupNodeVolume::ensureNodeSupportsBackups($target);
        foreach (['volume.mounts.v1', 'volume.delete.v1'] as $capability) {
            $target->ensureReportedCapability($capability);
        }
        $backups = [];
        foreach ($volumes as $volume) {
            $execution = $volume->uuid === data_get($restore, 'volume_uuid')
                ? RestoreNodeVolume::executions($volume)->where('uuid', data_get($restore, 'execution_uuid'))->first()
                : RestoreNodeVolume::latestExecution($volume);
            if ($execution === null) {
                throw new RuntimeException("The volume {$volume->name} has no successful backup, so Coolify cannot restore it while server {$source->name} is offline. Bring {$source->name} back online, then move the application.");
            }
            BackupNodeVolume::storage($execution->scheduledVolumeBackup, $workload);
            $backups[$volume->uuid] = $execution;
        }

        return $backups;
    }

    /**
     * Every volume of the workload with data. Throws when one is on another server than the source.
     *
     * @return Collection<int, NodeVolume>
     */
    private static function volumesOn(NodeWorkload $workload, Node $source): Collection
    {
        $volumes = $workload->volumes()->with('node')->whereNotNull('node_id')->orderBy('id')->get();
        $elsewhere = $volumes->first(fn (NodeVolume $volume): bool => $volume->node_id !== $source->id);
        if ($elsewhere !== null) {
            throw new RuntimeException("The volume {$elsewhere->name} is on server ".($elsewhere->node?->name ?? 'another server').", not on {$source->name}.");
        }

        return $volumes;
    }
}
