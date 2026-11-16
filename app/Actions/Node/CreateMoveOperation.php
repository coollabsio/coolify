<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeRegistry;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\User;
use App\Services\NodeStack\NodeStackCompose;
use App\Support\CpuArchitecture;
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
            // The services that share stack volumes with this one move with it (decision 0024).
            $group = self::group($workload, $source, $revision);
            foreach ($group as $member) {
                if ($member['revision']->mountsContainerSocket()) {
                    // The move deploys the socket on the target server.
                    NodeStackCompose::ensureMayMountContainerSocket($requestedBy, (int) $workload->team_id, $group->count() > 1 ? "The service {$member['workload']->stack_service}" : 'This application');
                }
                $mismatch = CpuArchitecture::mismatch($target, ReadImageArchitectures::forRevision($member['revision']));
                if ($mismatch !== null) {
                    throw new RuntimeException(($group->count() > 1 ? "The service {$member['workload']->stack_service} cannot move. " : '').$mismatch.' Select a server with one of these architectures.');
                }
            }
            if (NodeOperation::movingWorkloadIds($group->pluck('workload.id')->all()) !== []) {
                throw new RuntimeException($group->count() > 1 ? 'A service of this volume group already has an active move.' : 'This workload already has an active move.');
            }
            $workloads = $group->pluck('workload');
            $recoveryBackups = null;
            if ($recovery) {
                $recoveryBackups = array_map(fn (ScheduledVolumeBackupExecution $execution): string => $execution->uuid, self::recoveryBackups($workloads, $source, $target, $restore));
            } elseif ($source->id !== $target->id) {
                self::volumesToTransfer($workloads, $source, $target);
            }
            $members = $group->slice(1)->map(fn (array $member): array => [
                'workload_id' => $member['workload']->id,
                'workload_uuid' => $member['workload']->uuid,
                'revision_id' => $member['revision']->id,
            ])->values()->all();

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
                    ...($members === [] ? [] : ['group' => $members]),
                ],
                $requestedBy,
            );
        });
    }

    /**
     * The workload and the other services of its volume group (decision 0024), each with the
     * revision that the move deploys on the target: its latest one. The workload is first. Every
     * service of the group must run on the source, because its stack volumes are there.
     *
     * @return Collection<int, array{workload: NodeWorkload, revision: NodeWorkloadRevision}>
     */
    public static function group(NodeWorkload $workload, Node $source, ?NodeWorkloadRevision $revision = null): Collection
    {
        $members = $workload->volumeGroup();
        if ($members->count() > 1) {
            NodeWorkload::query()->withoutGlobalScopes()->whereKey($members->pluck('id')->all())->lockForUpdate()->get();
        }

        return $members->map(function (NodeWorkload $member) use ($workload, $source, $revision): array {
            if ($member->is($workload)) {
                return ['workload' => $workload, 'revision' => $revision ?? $workload->revisions()->latest('id')->firstOrFail()];
            }
            if (! $member->nodes()->whereKey($source->id)->exists()) {
                throw new RuntimeException("The service {$member->stack_service} shares a volume with {$workload->stack_service}, but it does not run on {$source->name}. Deploy it there first.");
            }
            $memberRevision = $member->revisions()->latest('id')->first();
            if ($memberRevision === null) {
                throw new RuntimeException("Deploy the service {$member->stack_service} first. It shares a volume with {$workload->stack_service}, so it moves with it.");
            }
            if (NodeRegistry::isLocalImage($memberRevision->image)) {
                throw new RuntimeException("The image of the service {$member->stack_service} is only on {$source->name}. Deploy it again, then move.");
            }

            return ['workload' => $member, 'revision' => $memberRevision];
        })->values();
    }

    /**
     * The volumes that a stateful move copies from the source: every volume with data on the
     * source of every workload that moves. Throws when a volume is on another Node or a Node
     * cannot take part in the copy.
     *
     * @param  NodeWorkload|Collection<int, NodeWorkload>  $workloads
     * @return Collection<int, NodeVolume>
     */
    public static function volumesToTransfer(NodeWorkload|Collection $workloads, Node $source, Node $target): Collection
    {
        $volumes = self::volumesOn($workloads, $source);
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
     * @param  NodeWorkload|Collection<int, NodeWorkload>  $workloads
     * @param  array{volume_uuid: string, execution_uuid: string}|null  $restore
     * @return array<string, ScheduledVolumeBackupExecution>
     */
    public static function recoveryBackups(NodeWorkload|Collection $workloads, Node $source, Node $target, ?array $restore = null): array
    {
        $volumes = self::volumesOn($workloads, $source);
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
            BackupNodeVolume::storage($execution->scheduledVolumeBackup, $volume->backupWorkload() ?? self::workloads($workloads)->first());
            $backups[$volume->uuid] = $execution;
        }

        return $backups;
    }

    /**
     * Every volume with data of the workloads, each once. Throws when one is on another server than the source.
     *
     * @param  NodeWorkload|Collection<int, NodeWorkload>  $workloads
     * @return Collection<int, NodeVolume>
     */
    public static function volumesOn(NodeWorkload|Collection $workloads, Node $source): Collection
    {
        $ids = self::workloads($workloads)->flatMap(fn (NodeWorkload $workload) => $workload->allVolumes()->modelKeys())->unique()->all();
        $volumes = NodeVolume::query()->whereKey($ids)->whereNotNull('node_id')->with('node')->orderBy('id')->get();
        $elsewhere = $volumes->first(fn (NodeVolume $volume): bool => $volume->node_id !== $source->id);
        if ($elsewhere !== null) {
            throw new RuntimeException("The volume {$elsewhere->name} is on server ".($elsewhere->node?->name ?? 'another server').", not on {$source->name}.");
        }

        return $volumes;
    }

    /**
     * @param  NodeWorkload|Collection<int, NodeWorkload>  $workloads
     * @return Collection<int, NodeWorkload>
     */
    private static function workloads(NodeWorkload|Collection $workloads): Collection
    {
        return $workloads instanceof NodeWorkload ? collect([$workloads]) : $workloads->values();
    }
}
