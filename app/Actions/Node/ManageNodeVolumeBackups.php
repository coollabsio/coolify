<?php

namespace App\Actions\Node;

use App\Jobs\VolumeBackupJob;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * The volume backups of a cluster application, database, or stack service (decision 0009), for
 * the storage backup pages and the API (decision 0029). Backups go to one S3 storage of the team
 * of the workload; there is no local archive. The caller authorizes `update` on the workload. An
 * S3 storage that no schedule of the workload uses yet is a team credential (`useTeamCredentials`).
 */
class ManageNodeVolumeBackups
{
    use AsAction;

    /**
     * @param  string  $source  `ui` or `api`, for the audit log.
     *
     * @throws ValidationException `volume`, `frequency`, or `s3_storage`.
     */
    public function createSchedule(NodeWorkload $workload, ?NodeVolume $volume, string $frequency, ?S3Storage $s3, User $user, string $source = 'ui'): ScheduledVolumeBackup
    {
        if (! validate_cron_expression($frequency)) {
            throw ValidationException::withMessages(['frequency' => 'The frequency must be a valid cron or human expression.']);
        }
        if ($volume === null || ! self::volumesOf($workload)->contains('id', $volume->id) || $volume->scheduledBackups()->exists()) {
            throw ValidationException::withMessages(['volume' => 'Select a volume of this application without a backup schedule.']);
        }
        if ($s3 === null || ! self::storages($workload)->whereKey($s3->id)->exists()) {
            throw ValidationException::withMessages(['s3_storage' => 'Select a usable S3 storage of the team.']);
        }
        self::authorizeStorage($workload, $s3, $user);

        $backup = $volume->scheduledBackups()->create([
            'team_id' => $workload->team_id,
            's3_storage_id' => $s3->id,
            'frequency' => $frequency,
            'enabled' => true,
            'save_s3' => true,
            'disable_local_backup' => true,
            'retention_amount_locally' => 0,
            'retention_days_locally' => 0,
            'retention_max_storage_locally' => 0,
        ]);
        $backup->syncS3Storages([$s3->id]);
        auditLog("{$source}.volume_backup.schedule_set", [
            'team_id' => $workload->team_id,
            'resource_type' => 'cluster-application',
            'resource_uuid' => $workload->uuid,
            'resource_name' => $workload->name,
            'storage_uuid' => $volume->uuid,
            'backup_uuid' => $backup->uuid,
            'created' => true,
        ]);

        return $backup;
    }

    /**
     * Saves the settings of a schedule. The current storage stays selectable while it is unusable.
     *
     * @param  array{frequency: string, enabled?: bool, stop_during_backup: bool, s3_storage: ?S3Storage, retention_amount_s3: int, retention_days_s3: int, retention_max_storage_s3: float|int|string, timeout: int, missing_backup_notification_days: int}  $input
     *
     * @throws ValidationException With the snake_case field names, and `s3_storage`.
     */
    public function updateSchedule(NodeWorkload $workload, ScheduledVolumeBackup $backup, array $input, User $user, string $source = 'ui'): ScheduledVolumeBackup
    {
        Validator::make($input, self::scheduleRules())->validate();
        if (! validate_cron_expression($input['frequency'])) {
            throw ValidationException::withMessages(['frequency' => 'The frequency must be a valid cron or human expression.']);
        }
        if ($input['stop_during_backup'] && $backup->backupable instanceof NodeVolume && $backup->backupable->isStackVolume()) {
            throw ValidationException::withMessages(['stop_during_backup' => BackupNodeVolume::SHARED_STOP_ERROR]);
        }
        $s3 = $input['s3_storage'] ?? null;
        if ($s3 === null || ! self::storages($workload, $backup->s3_storage_id)->whereKey($s3->id)->exists()) {
            throw ValidationException::withMessages(['s3_storage' => 'Select a usable S3 storage of the team.']);
        }
        self::authorizeStorage($workload, $s3, $user);

        $backup->fill([
            'frequency' => $input['frequency'],
            ...(array_key_exists('enabled', $input) ? ['enabled' => (bool) $input['enabled']] : []),
            'stop_during_backup' => (bool) $input['stop_during_backup'],
            'retention_amount_s3' => $input['retention_amount_s3'],
            'retention_days_s3' => $input['retention_days_s3'],
            'retention_max_storage_s3' => $input['retention_max_storage_s3'],
            'timeout' => $input['timeout'],
            'missing_backup_notification_days' => $input['missing_backup_notification_days'],
        ]);
        $changedFields = auditChangedFields($backup);
        $backup->save();
        $backup->syncS3Storages([$s3->id]);
        if ($changedFields !== []) {
            auditLog("{$source}.volume_backup.schedule_set", [
                'team_id' => $workload->team_id,
                'resource_type' => 'cluster-application',
                'resource_uuid' => $workload->uuid,
                'backup_uuid' => $backup->uuid,
                'created' => false,
                'changed_fields' => $changedFields,
            ]);
        }

        return $backup;
    }

    /** Queues a backup now. It fails at once when the server of the volume or the S3 storage cannot take it. */
    public function runNow(NodeWorkload $workload, ScheduledVolumeBackup $backup, string $source = 'ui'): void
    {
        $node = $backup->backupable?->node;
        if ($node === null) {
            throw new RuntimeException('The volume has no data yet. Deploy the application first.');
        }
        BackupNodeVolume::ensureNodeSupportsBackups($node);
        BackupNodeVolume::storage($backup, $workload);
        VolumeBackupJob::dispatch($backup);
        auditLog("{$source}.volume_backup.started", [
            'team_id' => $workload->team_id,
            'resource_uuid' => $workload->uuid,
            'resource_name' => $workload->name,
            'backup_uuid' => $backup->uuid,
        ]);
    }

    /**
     * Queues the restore of a backup run to a server of the cluster (decision 0009). The restore
     * stops the workload, replaces the data, and starts it again.
     *
     * @throws ValidationException `server` when the user may not restore to that server.
     */
    public function restore(NodeWorkload $workload, ScheduledVolumeBackup $backup, ScheduledVolumeBackupExecution $execution, ?Node $target, User $user, string $source = 'ui'): NodeOperation
    {
        if ($target === null || ! self::restoreTargets($workload, $backup, $user)->contains('id', $target->id)) {
            throw ValidationException::withMessages(['server' => 'Select a server of the cluster.']);
        }
        $operation = RestoreNodeVolume::queue($backup->backupable, $execution, $target, $user, $workload);
        auditLog("{$source}.volume_backup.restore_started", [
            'team_id' => $workload->team_id,
            'resource_uuid' => $workload->uuid,
            'backup_uuid' => $backup->uuid,
            'execution_uuid' => $execution->uuid,
            'node_uuid' => $target->uuid,
        ]);

        return $operation;
    }

    /** @return array<string, list<string>> */
    public static function scheduleRules(): array
    {
        return [
            'frequency' => ['required', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'stop_during_backup' => ['required', 'boolean'],
            'retention_amount_s3' => ['required', 'integer', 'min:0', 'max:10000'],
            'retention_days_s3' => ['required', 'integer', 'min:0'],
            'retention_max_storage_s3' => ['required', 'numeric', 'min:0'],
            'timeout' => ['required', 'integer', 'min:60', 'max:36000'],
            'missing_backup_notification_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    /** @return Builder<ScheduledVolumeBackup> The schedules of the volumes that the workload mounts. */
    public static function schedulesOf(NodeWorkload $workload): Builder
    {
        return ScheduledVolumeBackup::query()
            ->where('backupable_type', (new NodeVolume)->getMorphClass())
            ->whereIn('backupable_id', NodeVolume::query()
                ->where('node_workload_id', $workload->id)
                ->orWhereHas('mounts', fn (Builder $mounts) => $mounts->where('node_workload_id', $workload->id))
                ->select('id'));
    }

    /**
     * The volumes that the workload mounts: its own ones that are not detached, and the stack
     * volumes that it shares with other services (decision 0024). `mount_path` is the path in
     * this workload.
     *
     * @return Collection<int, NodeVolume>
     */
    public static function volumesOf(NodeWorkload $workload): Collection
    {
        $own = $workload->volumes()->with('node')->whereNull('detached_at')->get();
        $shared = $workload->stackVolumes()->with('node')->get()
            ->each(fn (NodeVolume $volume) => $volume->setAttribute('mount_path', $volume->pivot->mount_path));

        return $own->concat($shared)->sortBy('mount_path')->values();
    }

    /** @return Builder<S3Storage> Usable S3 storages of the team of the workload, plus the current one. */
    public static function storages(NodeWorkload $workload, ?int $currentId = null): Builder
    {
        return S3Storage::query()
            ->where('team_id', $workload->team_id)
            ->where(fn (Builder $query) => $query->where('is_usable', true)->when($currentId !== null, fn (Builder $query) => $query->orWhere('id', $currentId)));
    }

    public static function authorizeStorage(NodeWorkload $workload, S3Storage $s3, User $user): void
    {
        if (! self::schedulesOf($workload)->where('s3_storage_id', $s3->id)->exists()) {
            Gate::forUser($user)->authorize('useTeamCredentials', $workload);
        }
    }

    /**
     * Servers of the cluster of the volume that can take the restored volume, where the user may
     * place resources: the server of the volume first.
     *
     * @return Collection<int, Node>
     */
    public static function restoreTargets(NodeWorkload $workload, ScheduledVolumeBackup $backup, User $user): Collection
    {
        $current = $backup->backupable?->node;
        if ($current === null || $current->node_cluster_id === null) {
            return collect();
        }

        return Node::query()
            ->where('team_id', $workload->team_id)
            ->where('node_cluster_id', $current->node_cluster_id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Node $node): bool => $node->role->runsWorkloads() && self::supportsBackups($node) && Gate::forUser($user)->allows('placeResource', $node))
            ->sortBy(fn (Node $node): int => $node->id === $current->id ? 0 : 1)
            ->values();
    }

    public static function supportsBackups(?Node $node): bool
    {
        return $node !== null && collect(BackupNodeVolume::CAPABILITIES)->every(fn (string $capability): bool => $node->supportsCapability($capability) === true);
    }
}
