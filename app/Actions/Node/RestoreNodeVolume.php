<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Enums\NodeVolumeCopyState;
use App\Enums\NodeWorkloadAction;
use App\Jobs\ManageNodeWorkloadJob;
use App\Jobs\MoveNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeVolume;
use App\Models\NodeVolumeCopy;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Restores a volume backup (decision 0009). A restore is a `workload.move.v1` operation with a
 * `restore` request, so it blocks deploys, lifecycle actions, and the reconciler like a move.
 *
 * To another server of the cluster, MoveNodeWorkloadJob runs a move that takes the restored
 * volume from S3 instead of the source. On the server of the volume, handle() downloads the
 * archive into a new volume while the application runs, stops it, switches the data of the two
 * volumes, and starts it again. The old data stays in the new volume as a retained copy.
 */
class RestoreNodeVolume
{
    use AsAction;

    public const int POLL_SECONDS = 2;

    /**
     * Validates the restore and queues it.
     */
    public static function queue(NodeVolume $volume, ScheduledVolumeBackupExecution $execution, Node $target, ?User $requestedBy): NodeOperation
    {
        $volume->loadMissing(['workload', 'node']);
        $workload = $volume->workload;
        $backup = $execution->scheduledVolumeBackup;
        if ($backup === null || ! $backup->isNodeVolume() || $backup->backupable_id !== $volume->id
            || $execution->status !== 'success' || blank($execution->filename) || blank($execution->sha256)
            || $execution->s3_storage_deleted) {
            throw new RuntimeException('This backup cannot be restored.');
        }
        if ($volume->isDetached() || $volume->node === null) {
            throw new RuntimeException('Only a volume that the application mounts and that has data can be restored.');
        }
        if ($target->team_id !== $workload->team_id || $target->node_cluster_id === null || $target->node_cluster_id !== $volume->node->node_cluster_id) {
            throw new RuntimeException('Select a server in the cluster of the volume.');
        }
        BackupNodeVolume::storage($backup, $workload);
        BackupNodeVolume::ensureNodeSupportsBackups($target);
        $source = $workload->nodes()->whereKey($volume->node_id)->first();
        if ($source === null) {
            throw new RuntimeException('The application does not run on the server of the volume. Deploy it there first.');
        }
        $revision = $workload->revisions()->latest('id')->first();
        if ($revision === null) {
            throw new RuntimeException('Deploy the application first.');
        }

        $operation = CreateMoveOperation::run($source, $target, $revision, $requestedBy, [
            'volume_uuid' => $volume->uuid,
            'execution_uuid' => $execution->uuid,
        ]);
        MoveNodeWorkloadJob::dispatch($operation->id);

        return $operation;
    }

    /**
     * The volume and the backup execution of a restore operation, checked against its workload.
     *
     * @return array{volume: NodeVolume, execution: ScheduledVolumeBackupExecution}
     */
    public static function target(NodeOperation $operation): array
    {
        $volume = NodeVolume::query()
            ->where('node_workload_id', $operation->node_workload_id)
            ->where('uuid', data_get($operation->request, 'restore.volume_uuid'))
            ->firstOrFail();
        $execution = ScheduledVolumeBackupExecution::query()
            ->with('scheduledVolumeBackup')
            ->where('uuid', data_get($operation->request, 'restore.execution_uuid'))
            ->whereHas('scheduledVolumeBackup', fn ($backups) => $backups
                ->where('backupable_type', $volume->getMorphClass())
                ->where('backupable_id', $volume->id))
            ->firstOrFail();

        return ['volume' => $volume, 'execution' => $execution];
    }

    /** Restores on the server of the volume. Each step writes a checkpoint first, for cleanUp(). */
    public function handle(NodeOperation $operation, Carbon $deadline): void
    {
        ['volume' => $volume, 'execution' => $execution] = self::target($operation);
        $node = $operation->node;
        if ($volume->node_id !== $node->id) {
            throw new RuntimeException('The volume moved to another server. Start the restore again.');
        }
        self::checkpoint($operation, ['restore_in_place' => true, 'volume_uuids' => [$volume->uuid]]);

        $copy = DB::transaction(function () use ($operation, $volume, $node): NodeVolumeCopy {
            $uuid = new_public_id();
            $copy = NodeVolumeCopy::query()->create([
                'uuid' => $uuid,
                'node_volume_id' => $volume->id,
                'node_id' => $node->id,
                'podman_name' => NodeVolume::PODMAN_PREFIX.$uuid,
                'state' => NodeVolumeCopyState::RECEIVING,
            ]);
            self::checkpoint($operation, ['staging_copy_uuid' => $copy->uuid]);

            return $copy;
        });
        self::download($node, $operation, $copy->podmanName(), $execution, $deadline);

        // Downtime starts. A fresh inventory decides whether the container runs.
        FetchContainers::run($node);
        $running = $node->containers()
            ->where('node_workload_id', $operation->workload->id)
            ->where('is_managed', true)
            ->whereNotIn('state', ['exited', 'stopped', 'created', 'configured', 'dead'])
            ->exists();
        if ($running) {
            self::checkpoint($operation, ['source_stopped' => true]);
            self::lifecycle($operation, NodeWorkloadAction::STOP, 'The application could not be stopped for the restore.');
        }

        self::checkpoint($operation, ['switch_started' => true]);
        self::switchData($node, $operation->uuid, $copy->podmanName(), $volume->podmanName());
        $copy->update(['state' => NodeVolumeCopyState::RETAINED, 'delete_after' => now()->addDays(MoveNodeWorkloadJob::RETAIN_SOURCE_DAYS)]);
        self::checkpoint($operation, ['switched' => true]);

        if ($running) {
            self::lifecycle($operation, NodeWorkloadAction::START, 'The application did not start with the restored volume.');
        }
        self::checkpoint($operation, ['target_ready' => true]);
    }

    /**
     * Undoes a failed restore on the server of the volume: the old data goes back to the volume,
     * the restored data is deleted, and a stopped application starts again.
     */
    public static function cleanUp(NodeOperation $operation): void
    {
        $result = $operation->refresh()->result ?? [];
        if (data_get($result, 'cleaned_up') || data_get($result, 'target_ready')) {
            return;
        }
        $node = $operation->node;
        $volume = NodeVolume::query()->where('node_workload_id', $operation->node_workload_id)->whereIn('uuid', data_get($result, 'volume_uuids', []))->first();
        $copy = NodeVolumeCopy::query()->where('uuid', data_get($result, 'staging_copy_uuid'))->first();
        $notes = [];

        if ($volume !== null && $copy !== null && data_get($result, 'switch_started')) {
            try {
                // The first call completes a switch that stopped halfway; the second one undoes it.
                self::switchData($node, $operation->uuid, $copy->podmanName(), $volume->podmanName());
                self::switchData($node, $operation->uuid.'-rollback', $copy->podmanName(), $volume->podmanName());
                $notes['switch_back'] = 'done';
            } catch (Throwable $exception) {
                $notes['switch_back'] = 'failed: '.mb_substr($exception->getMessage(), 0, 500);
                self::checkpoint($operation, ['rollback' => $notes]);
                // The volume can hold the restored data or the old data: keep both, the application stays stopped.
                $copy->update(['state' => NodeVolumeCopyState::RETAINED, 'delete_after' => now()->addDays(MoveNodeWorkloadJob::RETAIN_SOURCE_DAYS)]);
                self::checkpoint($operation, ['cleaned_up' => true]);

                return;
            }
        }
        if ($copy !== null) {
            try {
                DeleteNodeVolumes::run($node, [$copy->podmanName()]);
                $copy->delete();
                $notes['restored_volume'] = 'deleted';
            } catch (Throwable $exception) {
                $copy->update(['state' => NodeVolumeCopyState::RETAINED, 'delete_after' => now()]);
                $notes['restored_volume'] = 'kept for the hourly cleanup: '.mb_substr($exception->getMessage(), 0, 500);
            }
        }
        if (data_get($result, 'source_stopped')) {
            try {
                self::lifecycle($operation, NodeWorkloadAction::START, 'The application could not be started again.');
                $notes['source_start'] = 'succeeded';
            } catch (Throwable $exception) {
                $notes['source_start'] = 'failed: '.mb_substr($exception->getMessage(), 0, 500);
            }
        }
        self::checkpoint($operation, ['rollback' => $notes, 'cleaned_up' => true]);
    }

    /**
     * Downloads the archive into a volume on the Node and polls until Sentinel checked it. Every
     * poll signs a new URL and touches the operation, so stale operation recovery does not take
     * over a long download.
     */
    public static function download(Node $node, NodeOperation $operation, string $volumeName, ScheduledVolumeBackupExecution $execution, Carbon $deadline): void
    {
        $backup = $execution->scheduledVolumeBackup;
        $s3 = BackupNodeVolume::storage($backup, $operation->workload);
        $connectionErrors = 0;
        $retried = false;
        while (true) {
            if (now()->gte($deadline)) {
                throw new RuntimeException('The download of the backup did not finish in time.');
            }
            try {
                $state = self::send($node, [
                    'restore_id' => $operation->uuid,
                    'step' => 'download',
                    'volume_name' => $volumeName,
                    'url' => BackupNodeVolume::downloadUrl($s3, (string) $execution->filename),
                    'sha256' => (string) $execution->sha256,
                    'archive_bytes' => (int) $execution->size,
                ]);
                $connectionErrors = 0;
            } catch (ConnectionException $exception) {
                if (++$connectionErrors >= 5) {
                    throw $exception;
                }
                Sleep::for(self::POLL_SECONDS)->seconds();

                continue;
            }
            $operation->touch();
            self::checkpoint($operation, ['restore_download' => ['state' => $state['state'], 'bytes_done' => $state['bytes_done'], 'bytes_total' => $state['bytes_total']]]);
            if ($state['state'] === 'succeeded') {
                return;
            }
            if ($state['state'] === 'failed') {
                // A Sentinel restart fails a running download; the next call starts it again once.
                if ($retried || str_contains($state['error'], 'checksum')) {
                    throw new RuntimeException('The backup could not be restored: '.BackupNodeVolume::redactUrls($state['error'] !== '' ? $state['error'] : 'Sentinel reported no reason.'));
                }
                $retried = true;
            }
            Sleep::for(self::POLL_SECONDS)->seconds();
        }
    }

    /** Gives `$to` the data of `$from` and `$from` the old data of `$to`. Idempotent per restore ID. */
    public static function switchData(Node $node, string $restoreId, string $from, string $to): void
    {
        $state = self::send($node, [
            'restore_id' => $restoreId,
            'step' => 'switch',
            'volume_name' => $from,
            'target_volume_name' => $to,
        ]);
        if ($state['state'] !== 'succeeded') {
            throw new RuntimeException('The volume data could not be switched: '.$state['error']);
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{state: string, bytes_total: int, bytes_done: int, error: string}
     */
    private static function send(Node $node, array $request): array
    {
        $node->ensureReportedCapability('volume.restore.v1');
        $commandId = (string) Str::uuid();
        try {
            $data = SendFluxCommand::run('volume.restore', [...$request, 'server_id' => $node->uuid, 'command_id' => $commandId]);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(BackupNodeVolume::redactUrls($exception->getMessage()), $exception->getCode());
        }
        $validator = Validator::make($data, [
            'command_id' => ['required', 'string', 'in:'.$commandId],
            'state' => ['required', 'in:running,succeeded,failed'],
            'bytes_total' => ['required', 'integer', 'min:0'],
            'bytes_done' => ['required', 'integer', 'min:0'],
            'error' => ['nullable', 'string', 'max:4000'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid volume restore state.');
        }
        $validated = $validator->validated();

        return [
            'state' => $validated['state'],
            'bytes_total' => (int) $validated['bytes_total'],
            'bytes_done' => (int) $validated['bytes_done'],
            'error' => (string) ($validated['error'] ?? ''),
        ];
    }

    private static function lifecycle(NodeOperation $operation, NodeWorkloadAction $action, string $error): void
    {
        $lifecycle = CreateLifecycleOperation::run(
            $operation->node,
            $operation->revision,
            $action,
            $operation->requestedBy,
            updateDesiredState: false,
            partOfMove: true,
            stopTimeoutSeconds: $action === NodeWorkloadAction::STOP ? MoveNodeWorkloadJob::SOURCE_STOP_TIMEOUT_SECONDS : null,
        );
        (new ManageNodeWorkloadJob($lifecycle->id))->handle();
        if ($lifecycle->refresh()->status !== NodeOperationStatus::SUCCEEDED) {
            throw new RuntimeException($error.' '.($lifecycle->error ?? ''));
        }
    }

    /** @param array<string, mixed> $values */
    private static function checkpoint(NodeOperation $operation, array $values): void
    {
        $operation->update(['result' => [...($operation->refresh()->result ?? []), ...$values]]);
    }
}
