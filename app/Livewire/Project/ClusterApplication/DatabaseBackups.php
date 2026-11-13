<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\DumpNodeDatabase;
use App\Actions\Node\RestoreNodeDatabase;
use App\Jobs\DatabaseBackupJob;
use App\Models\NodeWorkload;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Dump backups of a cluster database (decision 0010): the v4 database backup pages for a
 * NodeWorkload. Sentinel streams each dump to one S3 storage of the team of the database; there
 * is no local file, so the local retention does not apply. An execution restores into the
 * running database.
 */
class DatabaseBackups extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use WithPagination;

    #[Locked]
    public NodeWorkload $workload;

    /** The open schedule; null lists every schedule. */
    #[Locked]
    public ?string $backupUuid = null;

    public string $newFrequency = 'daily';

    public string $newS3StorageId = '';

    public string $frequency = 'daily';

    public string $databasesToBackup = '';

    public bool $dumpAll = false;

    public string $s3StorageId = '';

    public int $retentionAmountS3 = 7;

    public int $retentionDaysS3 = 0;

    public float $retentionMaxStorageS3 = 0;

    public int $timeout = 3600;

    public int $missingBackupNotificationDays = 0;

    public bool $delete_backup_s3 = true;

    public bool $delete_associated_backups_s3 = false;

    public int $perPage = 10;

    public string $timezone = '';

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return $this->teamChannelListeners(['BackupCreated' => '$refresh']);
    }

    /** Whether `$uuid` names a dump backup schedule of the database. */
    public static function ownsSchedule(NodeWorkload $workload, ?string $uuid): bool
    {
        return $uuid !== null && self::schedulesOf($workload)->where('uuid', $uuid)->exists();
    }

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
            'newFrequency' => ['required', 'string'],
            'newS3StorageId' => ['required', 'integer'],
        ]);
        if (! $this->supportsDumps()) {
            $this->dispatch('error', 'Dump backups are not available for this database.');

            return;
        }
        if (! validate_cron_expression($this->newFrequency)) {
            $this->addError('newFrequency', 'The frequency must be a valid cron or human expression.');

            return;
        }
        $s3 = $this->storages()->whereKey((int) $this->newS3StorageId)->first();
        if ($s3 === null) {
            $this->addError('newS3StorageId', 'Select a usable S3 storage of the team.');

            return;
        }
        $key = $this->workload->database_type->backupDatabaseKey();

        $backup = ScheduledDatabaseBackup::query()->create([
            'team_id' => $this->workload->team_id,
            'database_type' => $this->workload->getMorphClass(),
            'database_id' => $this->workload->id,
            'frequency' => $this->newFrequency,
            'enabled' => true,
            'save_s3' => true,
            'disable_local_backup' => true,
            's3_storage_id' => $s3->id,
            'databases_to_backup' => $key === null ? null : $this->workload->environment_variables()->where('key', $key)->value('value'),
            'dump_all' => false,
            'database_backup_retention_amount_locally' => 0,
            'database_backup_retention_days_locally' => 0,
            'database_backup_retention_max_storage_locally' => 0,
            'database_backup_retention_amount_s3' => 7,
        ]);
        $backup->syncS3Storages([$s3->id]);
        auditLog('ui.database.backup_schedule_created', [
            'team_id' => $this->workload->team_id,
            'database_uuid' => $this->workload->uuid,
            'database_name' => $this->workload->name,
            'backup_uuid' => $backup->uuid,
            'frequency' => $backup->frequency,
            'save_s3' => true,
        ]);
        $this->dispatch('success', 'Backup schedule created.');
        $this->redirectRoute('project.cluster-application.backup.show', [...$this->routeParameters(), 'backup_uuid' => $backup->uuid], navigate: true);
    }

    public function save(): void
    {
        $this->authorize('update', $this->workload);
        $backup = $this->backup();
        $this->validate([
            'frequency' => ['required', 'string'],
            'databasesToBackup' => ['nullable', 'string', 'max:2000', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}(\s*,\s*[A-Za-z0-9_][A-Za-z0-9_-]{0,63})*$/'],
            'dumpAll' => ['required', 'boolean'],
            's3StorageId' => ['required', 'integer'],
            'retentionAmountS3' => ['required', 'integer', 'min:0', 'max:10000'],
            'retentionDaysS3' => ['required', 'integer', 'min:0'],
            'retentionMaxStorageS3' => ['required', 'numeric', 'min:0'],
            'timeout' => ['required', 'integer', 'min:60', 'max:36000'],
            'missingBackupNotificationDays' => ['required', 'integer', 'min:0', 'max:365'],
        ], ['databasesToBackup.regex' => 'Enter database names separated by commas. A name has letters, digits, `_`, and `-`.']);
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
            'databases_to_backup' => filled($this->databasesToBackup) ? $this->databasesToBackup : null,
            'dump_all' => $this->dumpAll,
            's3_storage_id' => $s3->id,
            'database_backup_retention_amount_s3' => $this->retentionAmountS3,
            'database_backup_retention_days_s3' => $this->retentionDaysS3,
            'database_backup_retention_max_storage_s3' => $this->retentionMaxStorageS3,
            'timeout' => $this->timeout,
            'missing_backup_notification_days' => $this->missingBackupNotificationDays,
        ]);
        $changedFields = auditChangedFields($backup);
        $backup->save();
        $backup->syncS3Storages([$s3->id]);
        if ($changedFields !== []) {
            auditLog('ui.database.backup_schedule_updated', [
                'team_id' => $this->workload->team_id,
                'database_uuid' => $this->workload->uuid,
                'backup_uuid' => $backup->uuid,
                'changed_fields' => $changedFields,
            ]);
        }
        $this->dispatch('success', 'Backup schedule saved.');
    }

    public function toggleEnabled(): void
    {
        $this->authorize('update', $this->workload);
        $backup = $this->backup();
        $backup->update(['enabled' => ! $backup->enabled]);
        $this->dispatch('success', $backup->enabled ? 'Backups enabled.' : 'Backups disabled.');
    }

    public function backupNow(): void
    {
        $this->authorize('update', $this->workload);
        $backup = $this->backup();
        try {
            DumpNodeDatabase::node(DumpNodeDatabase::workload($backup));
            DumpNodeDatabase::storage($backup, $this->workload);
        } catch (Throwable $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        DatabaseBackupJob::dispatch($backup);
        auditLog('ui.database.backup_started', [
            'team_id' => $this->workload->team_id,
            'database_uuid' => $this->workload->uuid,
            'database_name' => $this->workload->name,
            'backup_uuid' => $backup->uuid,
        ]);
        $this->dispatch('success', 'Backup queued.');
    }

    public function restore(int $executionId, string $password = '', array $selectedActions = []): bool|string
    {
        $this->authorize('update', $this->workload);
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }
        $execution = $this->backup()->executions()->whereKey($executionId)->firstOrFail();
        try {
            RestoreNodeDatabase::queue($this->workload, $execution, auth()->user());
        } catch (Throwable $exception) {
            $this->dispatch('error', $exception->getMessage());

            return false;
        }
        auditLog('ui.database.backup_restore_started', [
            'team_id' => $this->workload->team_id,
            'database_uuid' => $this->workload->uuid,
            'backup_uuid' => $execution->scheduledDatabaseBackup?->uuid,
            'execution_uuid' => $execution->uuid,
        ]);
        $this->dispatch('success', 'Restore queued.');

        return true;
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
            if (in_array('delete_associated_backups_s3', $selectedActions, true)) {
                $backup->executions()->get()->each(fn (ScheduledDatabaseBackupExecution $execution) => $execution->deleteS3Copies());
            }
            $backup->executions()->delete();
            $backup->delete();
        } catch (Throwable $exception) {
            $this->dispatch('error', 'Could not delete the backup schedule: '.$exception->getMessage());

            return false;
        }
        auditLog('ui.database.backup_schedule_deleted', [
            'team_id' => $this->workload->team_id,
            'database_uuid' => $this->workload->uuid,
            'database_name' => $this->workload->name,
            'backup_uuid' => $backup->uuid,
        ]);
        $this->dispatch('success', 'Backup schedule deleted.');
        $this->redirectRoute('project.cluster-application.backups', $this->routeParameters(), navigate: true);

        return true;
    }

    public function render(): View
    {
        $backup = $this->backupUuid === null ? null : $this->backup();
        $node = $this->workload->nodes()->orderBy('nodes.id')->first();
        $restore = RestoreNodeDatabase::state($this->workload);

        return view('livewire.project.cluster-application.database-backups', [
            'backup' => $backup,
            'schedules' => $this->schedules()->with(['latest_log', 's3'])->withCount('executions')->latest()->get(),
            's3Options' => $this->storages($backup?->s3_storage_id)->orderBy('name')->get()
                ->map(fn (S3Storage $s3): array => ['value' => (string) $s3->id, 'label' => $s3->name.($s3->is_usable ? '' : ' (unavailable)')])
                ->all(),
            'executions' => $backup?->executions()->with('s3Replicas.s3')->paginate($this->perPage),
            'supportsDumps' => $this->supportsDumps(),
            'hasDatabases' => $this->workload->database_type?->backupDatabaseKey() !== null,
            'nodeSupportsDumps' => $node !== null && collect(DumpNodeDatabase::CAPABILITIES)->every(fn (string $capability): bool => $node->supportsCapability($capability) === true),
            'restore' => $restore,
            'restoreActive' => in_array($restore['state'] ?? null, ['queued', 'running'], true),
            'routeParameters' => $this->routeParameters(),
        ]);
    }

    private function supportsDumps(): bool
    {
        return $this->workload->database_type?->dumpEngine() !== null;
    }

    private function backup(): ScheduledDatabaseBackup
    {
        return $this->schedules()->where('uuid', $this->backupUuid)->firstOrFail();
    }

    /** @return Builder<ScheduledDatabaseBackup> */
    private function schedules(): Builder
    {
        return self::schedulesOf($this->workload);
    }

    /** @return Builder<ScheduledDatabaseBackup> */
    private static function schedulesOf(NodeWorkload $workload): Builder
    {
        return ScheduledDatabaseBackup::query()
            ->where('database_type', $workload->getMorphClass())
            ->where('database_id', $workload->id);
    }

    /** Usable S3 storages of the team of the database, plus the current one. */
    private function storages(?int $currentId = null): Builder
    {
        return S3Storage::query()
            ->where('team_id', $this->workload->team_id)
            ->where(fn (Builder $query) => $query->where('is_usable', true)->when($currentId !== null, fn (Builder $query) => $query->orWhere('id', $currentId)));
    }

    private function fillForm(ScheduledDatabaseBackup $backup): void
    {
        $this->frequency = $backup->frequency;
        $this->databasesToBackup = (string) $backup->databases_to_backup;
        $this->dumpAll = (bool) $backup->dump_all;
        $this->s3StorageId = (string) ($backup->s3_storage_id ?? '');
        $this->retentionAmountS3 = (int) $backup->database_backup_retention_amount_s3;
        $this->retentionDaysS3 = (int) $backup->database_backup_retention_days_s3;
        $this->retentionMaxStorageS3 = (float) $backup->database_backup_retention_max_storage_s3;
        $this->timeout = (int) ($backup->timeout ?? 3600);
        $this->missingBackupNotificationDays = (int) $backup->missing_backup_notification_days;
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
