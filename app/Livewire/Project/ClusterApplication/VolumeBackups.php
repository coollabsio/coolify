<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\BackupNodeVolume;
use App\Actions\Node\RestoreNodeVolume;
use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Jobs\VolumeBackupJob;
use App\Models\Node;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Volume backups of a cluster application or database (decision 0009): the v4 storage backup
 * pages for its NodeVolumes. Backups go to one S3 storage of the team of the workload; there is
 * no local archive, so the local retention does not apply.
 */
class VolumeBackups extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Locked]
    public NodeWorkload $workload;

    /** The open schedule; null lists every schedule. */
    #[Locked]
    public ?string $backupUuid = null;

    public string $newVolumeUuid = '';

    public string $newFrequency = 'daily';

    public string $newS3StorageId = '';

    public string $frequency = 'daily';

    public bool $stopDuringBackup = false;

    public string $s3StorageId = '';

    public int $retentionAmountS3 = 7;

    public int $retentionDaysS3 = 0;

    public float $retentionMaxStorageS3 = 0;

    public int $timeout = ScheduledVolumeBackup::DEFAULT_TIMEOUT;

    public int $missingBackupNotificationDays = 0;

    public string $restoreNodeUuid = '';

    public bool $delete_backup_s3 = true;

    public bool $delete_associated_backups_s3 = false;

    public int $perPage = 10;

    public string $timezone = '';

    public function mount(NodeWorkload $workload, ?string $backupUuid = null): void
    {
        $this->authorize('view', $workload);
        $this->workload = $workload;
        if ($backupUuid !== null) {
            $backup = $this->schedules()->where('uuid', $backupUuid)->firstOrFail();
            $this->backupUuid = $backup->uuid;
            $this->fillForm($backup);
        }
        $this->newS3StorageId = (string) ($this->storages()->first()?->id ?? '');
        $this->timezone = instanceSettings()->instance_timezone ?: config('app.timezone');
    }

    public function createSchedule(): void
    {
        $this->authorize('update', $this->workload);
        $this->validate([
            'newVolumeUuid' => ['required', 'string'],
            'newFrequency' => ['required', 'string'],
            'newS3StorageId' => ['required', 'integer'],
        ]);
        if (! validate_cron_expression($this->newFrequency)) {
            $this->addError('newFrequency', 'The frequency must be a valid cron or human expression.');

            return;
        }
        $volume = $this->workload->volumes()->whereNull('detached_at')->where('uuid', $this->newVolumeUuid)->first();
        if ($volume === null || $volume->scheduledBackups()->exists()) {
            $this->addError('newVolumeUuid', 'Select a volume of this application without a backup schedule.');

            return;
        }
        $s3 = $this->storages()->whereKey((int) $this->newS3StorageId)->first();
        if ($s3 === null) {
            $this->addError('newS3StorageId', 'Select a usable S3 storage of the team.');

            return;
        }

        $backup = $volume->scheduledBackups()->create([
            'team_id' => $this->workload->team_id,
            's3_storage_id' => $s3->id,
            'frequency' => $this->newFrequency,
            'enabled' => true,
            'save_s3' => true,
            'disable_local_backup' => true,
            'retention_amount_locally' => 0,
            'retention_days_locally' => 0,
            'retention_max_storage_locally' => 0,
        ]);
        $backup->syncS3Storages([$s3->id]);
        auditLog('ui.volume_backup.schedule_set', [
            'team_id' => $this->workload->team_id,
            'resource_type' => 'cluster-application',
            'resource_uuid' => $this->workload->uuid,
            'resource_name' => $this->workload->name,
            'storage_uuid' => $volume->uuid,
            'backup_uuid' => $backup->uuid,
            'created' => true,
        ]);
        $this->dispatch('success', 'Storage backup schedule created.');
        $this->redirectRoute('project.cluster-application.backup.show', [...$this->routeParameters(), 'backup_uuid' => $backup->uuid], navigate: true);
    }

    public function save(): void
    {
        $this->authorize('update', $this->workload);
        $backup = $this->backup();
        $this->validate([
            'frequency' => ['required', 'string'],
            'stopDuringBackup' => ['required', 'boolean'],
            's3StorageId' => ['required', 'integer'],
            'retentionAmountS3' => ['required', 'integer', 'min:0', 'max:10000'],
            'retentionDaysS3' => ['required', 'integer', 'min:0'],
            'retentionMaxStorageS3' => ['required', 'numeric', 'min:0'],
            'timeout' => ['required', 'integer', 'min:60', 'max:36000'],
            'missingBackupNotificationDays' => ['required', 'integer', 'min:0', 'max:365'],
        ]);
        if (! validate_cron_expression($this->frequency)) {
            $this->addError('frequency', 'The frequency must be a valid cron or human expression.');

            return;
        }
        // The current storage stays selectable while it is unusable, so other settings can be saved.
        $s3 = $this->storages($backup->s3_storage_id)->whereKey((int) $this->s3StorageId)->first();
        if ($s3 === null) {
            $this->addError('s3StorageId', 'Select a usable S3 storage of the team.');

            return;
        }

        $backup->fill([
            'frequency' => $this->frequency,
            'stop_during_backup' => $this->stopDuringBackup,
            'retention_amount_s3' => $this->retentionAmountS3,
            'retention_days_s3' => $this->retentionDaysS3,
            'retention_max_storage_s3' => $this->retentionMaxStorageS3,
            'timeout' => $this->timeout,
            'missing_backup_notification_days' => $this->missingBackupNotificationDays,
        ]);
        $changedFields = auditChangedFields($backup);
        $backup->save();
        $backup->syncS3Storages([$s3->id]);
        if ($changedFields !== []) {
            auditLog('ui.volume_backup.schedule_set', [
                'team_id' => $this->workload->team_id,
                'resource_type' => 'cluster-application',
                'resource_uuid' => $this->workload->uuid,
                'backup_uuid' => $backup->uuid,
                'created' => false,
                'changed_fields' => $changedFields,
            ]);
        }
        $this->dispatch('success', 'Storage backup schedule saved.');
    }

    public function instantSave(): void
    {
        $this->save();
    }

    public function toggleEnabled(): void
    {
        $this->authorize('update', $this->workload);
        $backup = $this->backup();
        $backup->update(['enabled' => ! $backup->enabled]);
        $this->dispatch('success', $backup->enabled ? 'Storage backups enabled.' : 'Storage backups disabled.');
    }

    public function backupNow(): void
    {
        $this->authorize('update', $this->workload);
        $backup = $this->backup();
        try {
            $node = $backup->backupable?->node;
            if ($node === null) {
                throw new \RuntimeException('The volume has no data yet. Deploy the application first.');
            }
            BackupNodeVolume::ensureNodeSupportsBackups($node);
            BackupNodeVolume::storage($backup, $this->workload);
        } catch (Throwable $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        VolumeBackupJob::dispatch($backup);
        auditLog('ui.volume_backup.started', [
            'team_id' => $this->workload->team_id,
            'resource_uuid' => $this->workload->uuid,
            'resource_name' => $this->workload->name,
            'backup_uuid' => $backup->uuid,
        ]);
        $this->dispatch('success', 'Storage backup queued.');
    }

    public function restore(int $executionId): void
    {
        $this->authorize('update', $this->workload);
        $backup = $this->backup();
        $execution = $backup->executions()->whereKey($executionId)->firstOrFail();
        $target = $this->restoreTargets($backup)->firstWhere('uuid', $this->restoreNodeUuid);
        if ($target === null) {
            $this->addError('restoreNodeUuid', 'Select a server of the cluster.');

            return;
        }
        try {
            $operation = RestoreNodeVolume::queue($backup->backupable, $execution, $target, auth()->user());
        } catch (Throwable $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        auditLog('ui.volume_backup.restore_started', [
            'team_id' => $this->workload->team_id,
            'resource_uuid' => $this->workload->uuid,
            'backup_uuid' => $backup->uuid,
            'execution_uuid' => $execution->uuid,
            'node_uuid' => $target->uuid,
        ]);
        $this->dispatch('close-modal');
        $this->dispatch('success', 'Restore queued.');
        $this->redirectRoute('project.cluster-application.deployment.show', [...$this->routeParameters(), 'deployment_uuid' => $operation->uuid], navigate: true);
    }

    public function deleteBackup(int $executionId, string $password, array $selectedActions = []): bool|string
    {
        $this->authorize('delete', $this->workload);
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }
        $execution = $this->backup()->executions()->whereKey($executionId)->first();
        if ($execution === null || $execution->status === 'running') {
            $this->dispatch('error', 'Wait for the backup to finish before deleting it.');

            return false;
        }
        try {
            if (in_array('delete_backup_s3', $selectedActions, true)) {
                $execution->deleteS3Copies();
            }
            $execution->delete();
            $this->dispatch('success', 'Backup deleted.');

            return true;
        } catch (Throwable $exception) {
            $this->dispatch('error', 'Failed to delete backup: '.$exception->getMessage());

            return false;
        }
    }

    public function deleteSchedule(?string $password = null, array $selectedActions = []): bool|string
    {
        $this->authorize('delete', $this->workload);
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }
        $backup = $this->backup();
        try {
            DeleteScheduledVolumeBackup::run($backup, deleteLocalArchives: false, deleteS3Archives: in_array('delete_associated_backups_s3', $selectedActions, true));
        } catch (Throwable $exception) {
            $this->dispatch('error', 'Could not delete the backup schedule: '.$exception->getMessage());

            return false;
        }
        $this->dispatch('success', 'Storage backup schedule deleted.');
        $this->redirectRoute('project.cluster-application.backups', $this->routeParameters(), navigate: true);

        return true;
    }

    public function render(): View
    {
        $backup = $this->backupUuid === null ? null : $this->backup();
        $volumes = $this->workload->volumes()->with('node')->whereNull('detached_at')->orderBy('mount_path')->get();
        $schedules = $this->schedules()->with(['backupable.node', 'latestExecution', 's3'])->withCount('executions')->latest()->get();

        return view('livewire.project.cluster-application.volume-backups', [
            'backup' => $backup,
            'schedules' => $schedules,
            'newVolumeOptions' => $volumes
                ->reject(fn (NodeVolume $volume): bool => $schedules->contains('backupable_id', $volume->id))
                ->map(fn (NodeVolume $volume): array => ['value' => $volume->uuid, 'label' => "{$volume->name} ({$volume->mount_path})"])
                ->values()->all(),
            's3Options' => $this->storages($backup?->s3_storage_id)->orderBy('name')->get()
                ->map(fn (S3Storage $s3): array => ['value' => (string) $s3->id, 'label' => $s3->name.($s3->is_usable ? '' : ' (unavailable)')])
                ->all(),
            'executions' => $backup?->executions()->with('s3Replicas.s3')->paginate($this->perPage),
            'restoreOptions' => $backup ? $this->restoreTargets($backup)
                ->map(fn (Node $node): array => [
                    'value' => $node->uuid,
                    'label' => $node->name.($node->id === $backup->backupable?->node_id ? ' (current server)' : ''),
                ])->values()->all() : [],
            'nodeSupportsBackups' => $backup === null || $this->supportsBackups($backup->backupable?->node),
            'routeParameters' => $this->routeParameters(),
        ]);
    }

    private function backup(): ScheduledVolumeBackup
    {
        return $this->schedules()->with('backupable.node')->where('uuid', $this->backupUuid)->firstOrFail();
    }

    /** @return Builder<ScheduledVolumeBackup> */
    private function schedules(): Builder
    {
        return ScheduledVolumeBackup::query()
            ->where('backupable_type', (new NodeVolume)->getMorphClass())
            ->whereIn('backupable_id', NodeVolume::query()->where('node_workload_id', $this->workload->id)->select('id'));
    }

    /** Usable S3 storages of the team of the workload, plus the current one. */
    private function storages(?int $currentId = null): Builder
    {
        return S3Storage::query()
            ->where('team_id', $this->workload->team_id)
            ->where(fn (Builder $query) => $query->where('is_usable', true)->when($currentId !== null, fn (Builder $query) => $query->orWhere('id', $currentId)));
    }

    /**
     * Servers of the cluster of the volume that can take the restored volume: the server of the
     * volume first.
     *
     * @return Collection<int, Node>
     */
    private function restoreTargets(ScheduledVolumeBackup $backup): Collection
    {
        $current = $backup->backupable?->node;
        if ($current === null || $current->node_cluster_id === null) {
            return collect();
        }

        return Node::query()
            ->where('team_id', $this->workload->team_id)
            ->where('node_cluster_id', $current->node_cluster_id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Node $node): bool => $node->role->runsWorkloads() && $this->supportsBackups($node))
            ->sortBy(fn (Node $node): int => $node->id === $current->id ? 0 : 1)
            ->values();
    }

    private function supportsBackups(?Node $node): bool
    {
        return $node !== null && collect(BackupNodeVolume::CAPABILITIES)->every(fn (string $capability): bool => $node->supportsCapability($capability) === true);
    }

    private function fillForm(ScheduledVolumeBackup $backup): void
    {
        $this->frequency = $backup->frequency;
        $this->stopDuringBackup = $backup->stop_during_backup;
        $this->s3StorageId = (string) ($backup->s3_storage_id ?? '');
        $this->retentionAmountS3 = $backup->retention_amount_s3;
        $this->retentionDaysS3 = $backup->retention_days_s3;
        $this->retentionMaxStorageS3 = (float) $backup->retention_max_storage_s3;
        $this->timeout = $backup->timeout;
        $this->missingBackupNotificationDays = $backup->missing_backup_notification_days;
    }

    /** @return array{project_uuid: string, environment_uuid: string, workload_uuid: string} */
    private function routeParameters(): array
    {
        return [
            'project_uuid' => $this->workload->project()->value('uuid'),
            'environment_uuid' => $this->workload->environment()->value('uuid'),
            'workload_uuid' => $this->workload->uuid,
        ];
    }
}
