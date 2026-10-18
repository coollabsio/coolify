<?php

namespace App\Livewire\Project\Database;

use App\Jobs\DatabaseBackupJob;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ServiceDatabase;
use App\Models\StandalonePostgresql;
use App\Traits\ListensToTeamChannel;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class BackupEdit extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public ScheduledDatabaseBackup $backup;

    public string $section = 'general';

    #[Locked]
    public $availableS3Storages;

    #[Locked]
    public $parameters;

    #[Validate(['required', 'boolean'])]
    public bool $delete_associated_backups_locally = false;

    #[Validate(['required', 'boolean'])]
    public bool $delete_associated_backups_s3 = false;

    #[Validate(['required', 'boolean'])]
    public bool $delete_associated_backups_sftp = false;

    #[Validate(['nullable', 'string'])]
    public ?string $status = null;

    #[Validate(['required', 'boolean'])]
    public bool $backupEnabled = false;

    #[Validate(['required', 'string'])]
    public string $frequency = '';

    #[Validate(['string'])]
    public string $timezone = '';

    #[Validate(['required', 'integer'])]
    public int $databaseBackupRetentionAmountLocally = 0;

    #[Validate(['required', 'integer'])]
    public ?int $databaseBackupRetentionDaysLocally = 0;

    #[Validate(['required', 'numeric', 'min:0'])]
    public ?float $databaseBackupRetentionMaxStorageLocally = 0;

    #[Validate(['required', 'integer'])]
    public ?int $databaseBackupRetentionAmountS3 = 0;

    #[Validate(['required', 'integer'])]
    public ?int $databaseBackupRetentionDaysS3 = 0;

    #[Validate(['required', 'numeric', 'min:0'])]
    public ?float $databaseBackupRetentionMaxStorageS3 = 0;

    #[Validate(['required', 'boolean'])]
    public bool $saveS3 = false;

    #[Validate(['required', 'boolean'])]
    public bool $disableLocalBackup = false;

    /** @var array<int, int|string> */
    #[Validate(['array'])]
    public array $s3StorageIds = [];

    #[Validate(['nullable', 'string'])]
    public ?string $databasesToBackup = null;

    #[Validate(['required', 'boolean'])]
    public bool $dumpAll = false;

    #[Validate(['required', 'int', 'min:60', 'max:36000'])]
    public int|string $timeout = 3600;

    #[Validate(['required', 'integer', 'min:0', 'max:365'])]
    public int $missingBackupNotificationDays = 0;

    public function getListeners(): array
    {
        // Keep "Backup Now" in sync when the database starts/stops without a full page refresh.
        $listeners = ['databaseUpdated' => 'refreshStatus'];

        $user = Auth::user();
        if (! $user) {
            return $listeners;
        }

        $listeners["echo-private:user.{$user->id},DatabaseStatusChanged"] = 'refreshStatus';

        return [
            ...$listeners,
            ...$this->teamChannelListeners(['ServiceChecked' => 'refreshStatus']),
        ];
    }

    public function mount()
    {
        try {
            $this->authorize('view', $this->backup->database);
            $this->availableS3Storages = S3Storage::query()
                ->whereKey($this->availableS3StorageIds()->all())
                ->orderBy('name')
                ->get();
            $this->parameters = get_route_parameters();
            $this->syncData();
            $this->refreshStatus();
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function refreshStatus(): void
    {
        $database = $this->backup->database;
        if (! $database) {
            return;
        }

        $database->refresh();
        $this->status = $database->status;
    }

    /**
     * @return array<int, string> Names of the backup fields the save changed.
     */
    private function syncData(bool $toModel = false): array
    {
        if ($toModel) {
            $this->backup->enabled = $this->backupEnabled;
            $this->backup->frequency = $this->frequency;
            $this->backup->database_backup_retention_amount_locally = $this->databaseBackupRetentionAmountLocally;
            $this->backup->database_backup_retention_days_locally = $this->databaseBackupRetentionDaysLocally;
            $this->backup->database_backup_retention_max_storage_locally = $this->databaseBackupRetentionMaxStorageLocally;
            $this->backup->database_backup_retention_amount_s3 = $this->databaseBackupRetentionAmountS3;
            $this->backup->database_backup_retention_days_s3 = $this->databaseBackupRetentionDaysS3;
            $this->backup->database_backup_retention_max_storage_s3 = $this->databaseBackupRetentionMaxStorageS3;
            $this->backup->save_s3 = $this->saveS3;
            $this->backup->disable_local_backup = $this->disableLocalBackup;

            // Validate databases_to_backup to prevent command injection
            // Handles all formats including MongoDB's "db:col1,col2|db2:col3"
            if (filled($this->databasesToBackup)) {
                validateDatabasesBackupInput($this->databasesToBackup);
            }

            $this->backup->databases_to_backup = $this->databasesToBackup;
            $this->backup->dump_all = $this->dumpAll;
            $this->backup->timeout = $this->timeout;
            $this->backup->missing_backup_notification_days = $this->missingBackupNotificationDays;
            $storageIds = $this->customValidate();
            $changedFields = auditChangedFields($this->backup);
            $this->backup->save();

            $previousStorageIds = $this->backup->s3Storages()->pluck('s3_storages.id')->sort()->values()->all();
            $this->backup->syncS3Storages($storageIds->all());
            if ($previousStorageIds !== $storageIds->sort()->values()->all()) {
                $changedFields[] = 's3_storages';
            }

            return $changedFields;
        } else {
            $this->backupEnabled = $this->backup->enabled;
            $this->frequency = $this->backup->frequency;
            $this->timezone = data_get($this->backup->server(), 'settings.server_timezone', 'Instance timezone');
            $this->databaseBackupRetentionAmountLocally = $this->backup->database_backup_retention_amount_locally;
            $this->databaseBackupRetentionDaysLocally = $this->backup->database_backup_retention_days_locally;
            $this->databaseBackupRetentionMaxStorageLocally = $this->backup->database_backup_retention_max_storage_locally;
            $this->databaseBackupRetentionAmountS3 = $this->backup->database_backup_retention_amount_s3;
            $this->databaseBackupRetentionDaysS3 = $this->backup->database_backup_retention_days_s3;
            $this->databaseBackupRetentionMaxStorageS3 = $this->backup->database_backup_retention_max_storage_s3;
            $this->saveS3 = $this->backup->save_s3;
            $this->disableLocalBackup = $this->backup->disable_local_backup ?? false;
            $selectedIds = $this->backup->selectedS3Storages()->pluck('id');
            $this->s3StorageIds = ($selectedIds->isNotEmpty() ? $selectedIds : $this->availableS3StorageIds()->take(1))->all();
            $this->databasesToBackup = $this->backup->databases_to_backup;
            $this->dumpAll = $this->backup->dump_all;
            $this->timeout = $this->backup->timeout;
            $this->missingBackupNotificationDays = $this->backup->missing_backup_notification_days;
        }

        return [];
    }

    public function delete($password, $selectedActions = [])
    {
        $database = $this->backup->database;
        $this->authorize('manageBackups', $database);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        try {
            $server = null;
            if ($database instanceof ServiceDatabase) {
                $server = $database->service->destination->server;
            } elseif ($database->destination && $database->destination->server) {
                $server = $database->destination->server;
            }

            $filenames = $this->backup->executions()
                ->whereNotNull('filename')
                ->where('filename', '!=', '')
                ->where('scheduled_database_backup_id', $this->backup->id)
                ->pluck('filename')
                ->filter()
                ->all();

            if (! empty($filenames)) {
                if ($this->delete_associated_backups_locally && $server) {
                    deleteBackupsLocally($filenames, $server);
                }
            }

            if ($this->delete_associated_backups_s3) {
                $this->backup->executions()->each(fn ($execution) => $execution->deleteS3Copies());
            }

            $backupUuid = $this->backup->uuid;
            $this->backup->delete();
            $this->skipRender();
            auditLog('ui.database.backup_schedule_deleted', [
                'team_id' => $database->team()?->id,
                'database_uuid' => $database->uuid,
                'database_name' => $database->name,
                'backup_uuid' => $backupUuid,
            ]);

            if ($database instanceof ServiceDatabase) {
                return redirectRoute($this, 'project.service.database.backups', [
                    'project_uuid' => $database->service->project()->uuid,
                    'environment_uuid' => $database->service->environment->uuid,
                    'service_uuid' => $database->service->uuid,
                    'stack_service_uuid' => $database->uuid,
                ]);
            } else {
                return redirectRoute($this, 'project.database.backup.index', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'database_uuid' => $this->parameters['database_uuid'],
                ]);
            }
        } catch (Exception $e) {
            $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());

            return handleError($e, $this);
        }
    }

    public function backupNow()
    {
        try {
            $this->authorize('manageBackups', $this->backup->database);

            $database = $this->backup->database->refresh();
            $this->status = $database->status;
            if ($database->id !== 0 && ! str($database->status)->startsWith('running')) {
                $this->dispatch('error', 'The database must be running to start a backup.');

                return;
            }

            DatabaseBackupJob::dispatch($this->backup);
            $database = $this->backup->database;
            auditLog('ui.database.backup_started', [
                'team_id' => $database->team()?->id,
                'database_uuid' => $database->uuid,
                'database_name' => $database->name,
                'backup_uuid' => $this->backup->uuid,
            ]);
            $this->dispatch('success', 'Backup queued. It will be available in a few minutes.');

            if ($database instanceof ServiceDatabase) {
                return redirect()->route('project.service.database.backup.executions', [
                    'project_uuid' => $database->service->project()->uuid,
                    'environment_uuid' => $database->service->environment->uuid,
                    'service_uuid' => $database->service->uuid,
                    'stack_service_uuid' => $database->uuid,
                    'backup_uuid' => $this->backup->uuid,
                ]);
            }

            // Instance databases (e.g. coolify-db) have no project/environment.
            // Stay on the current page (settings.backup) instead of redirecting.
            $project = $database->project();
            $environment = $database->environment;
            if (! $project || ! $environment) {
                return null;
            }

            return redirect()->route('project.database.backup.executions', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'database_uuid' => $database->uuid,
                'backup_uuid' => $this->backup->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('manageBackups', $this->backup->database);

            $this->auditBackupScheduleUpdated($this->syncData(true));
            $this->dispatch('success', 'Backup updated successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function toggleEnabled(): void
    {
        try {
            $this->authorize('manageBackups', $this->backup->database);

            $this->backupEnabled = ! $this->backupEnabled;
            $this->backup->enabled = $this->backupEnabled;
            $changedFields = auditChangedFields($this->backup);
            $this->backup->save();
            $this->auditBackupScheduleUpdated($changedFields);
            $this->dispatch('success', $this->backupEnabled ? 'Backup enabled.' : 'Backup disabled.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function updatedS3StorageIds(): void
    {
        $this->instantSave();
    }

    public function toggleS3(): void
    {
        if (! $this->saveS3 && $this->selectedAvailableS3StorageIds()->isEmpty()) {
            $this->dispatch('error', 'Select a usable S3 storage before enabling S3 backups.');

            return;
        }

        $this->saveS3 = ! $this->saveS3;
        $this->disableLocalBackup = $this->saveS3 && $this->disableLocalBackup;
        $this->instantSave();
    }

    /**
     * @return Collection<int, int> The usable S3 storage IDs to keep as destinations.
     */
    private function customValidate(): Collection
    {
        // Only usable S3 storages of the database's team can be destinations; others are dropped.
        $storageIds = $this->selectedAvailableS3StorageIds();
        $this->s3StorageIds = $storageIds->all();

        if ($this->availableS3StorageIds()->isEmpty()) {
            $this->backup->save_s3 = $this->saveS3 = false;
        } elseif ($this->backup->save_s3 && $storageIds->isEmpty()) {
            $message = 'Select at least one usable S3 storage.';
            $this->addError('s3StorageIds', $message);

            throw new Exception($message);
        }

        // Validate that disable_local_backup can only be true when S3 backup is enabled
        if ($this->backup->disable_local_backup && ! $this->backup->save_s3) {
            $this->backup->disable_local_backup = $this->disableLocalBackup = false;
        }

        $isValid = validate_cron_expression($this->backup->frequency);
        if (! $isValid) {
            throw new Exception('Invalid Cron / Human expression');
        }
        $this->validate();

        return $storageIds;
    }

    /**
     * @param  array<int, string>  $changedFields
     */
    private function auditBackupScheduleUpdated(array $changedFields): void
    {
        if ($changedFields === []) {
            return;
        }

        $database = $this->backup->database;
        auditLog('ui.database.backup_schedule_updated', [
            'team_id' => $database?->team()?->id,
            'database_uuid' => $database?->uuid,
            'database_name' => $database?->name,
            'backup_uuid' => $this->backup->uuid,
            'changed_fields' => $changedFields,
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    private function selectedAvailableS3StorageIds(): Collection
    {
        $availableIds = $this->availableS3StorageIds();

        return collect($this->s3StorageIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $availableIds->contains($id))
            ->unique()
            ->values();
    }

    private function availableS3StorageIds(): Collection
    {
        $database = $this->backup->database;
        // The instance database (id 0) has no project and belongs to the root team.
        $teamId = $database instanceof StandalonePostgresql && $database->id === 0 ? 0 : $database?->team()?->id;

        if ($teamId === null) {
            return collect();
        }

        // Current destinations stay selectable while unusable, so saving other settings does not remove them.
        $selectedIds = $this->backup->selectedS3Storages()->pluck('id')->all();

        return S3Storage::query()
            ->where('team_id', $teamId)
            ->where(fn (Builder $query) => $query->where('is_usable', true)->orWhereIn('id', $selectedIds))
            ->pluck('id');
    }

    public function submit()
    {
        try {
            $this->authorize('manageBackups', $this->backup->database);

            $this->auditBackupScheduleUpdated($this->syncData(true));
            $this->dispatch('success', 'Backup updated successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.project.database.backup-edit', [
            'checkboxes' => [
                ['id' => 'delete_associated_backups_locally', 'label' => __('database.delete_backups_locally')],
                ['id' => 'delete_associated_backups_s3', 'label' => 'All backups will be permanently deleted (associated with this backup job) from every S3 storage of this schedule.'],
                // ['id' => 'delete_associated_backups_sftp', 'label' => 'All backups associated with this backup job from this database will be permanently deleted from the selected SFTP Storage.']
            ],
        ]);
    }
}
