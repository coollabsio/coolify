<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ServiceDatabase;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class BackupEdit extends Component
{
    use AuthorizesRequests;

    public ScheduledDatabaseBackup $backup;

    #[Locked]
    public $s3s;

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

    #[Validate(['required', 'string', 'in:dump,pgbackrest'])]
    public string $backupMethod = 'dump';

    #[Validate(['required', 'string', 'in:full,diff,incr'])]
    public string $pgBackRestBackupType = 'incr';

    #[Validate(['required', 'boolean'])]
    public bool $pgBackRestRequireWalArchive = true;

    #[Validate(['required', 'boolean'])]
    public bool $disableLocalBackup = false;

    #[Validate(['nullable', 'integer'])]
    public ?int $s3StorageId = 1;

    #[Validate(['nullable', 'string'])]
    public ?string $databasesToBackup = null;

    #[Validate(['required', 'boolean'])]
    public bool $dumpAll = false;

    #[Validate(['required', 'int', 'min:60', 'max:36000'])]
    public int|string $timeout = 3600;

    public bool $isPostgres = false;

    public function mount()
    {
        try {
            $this->authorize('view', $this->backup->database);
            $this->parameters = get_route_parameters();
            $this->s3s = currentTeam()->s3s;
            $this->isPostgres = $this->isPostgresDatabase();
            $this->syncData();
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
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
            $this->backup->backup_method = $this->backupMethod;
            $this->backup->pgbackrest_backup_type = $this->pgBackRestBackupType;
            $this->backup->pgbackrest_require_wal_archive = $this->pgBackRestRequireWalArchive;
            $this->backup->disable_local_backup = $this->disableLocalBackup;
            $this->backup->s3_storage_id = $this->s3StorageId;

            // Validate databases_to_backup to prevent command injection
            // Handles all formats including MongoDB's "db:col1,col2|db2:col3"
            if ($this->backupMethod !== 'pgbackrest' && filled($this->databasesToBackup)) {
                validateDatabasesBackupInput($this->databasesToBackup);
            }

            $this->backup->databases_to_backup = $this->databasesToBackup;
            $this->backup->dump_all = $this->dumpAll;
            $this->backup->timeout = $this->timeout;
            $this->customValidate();
            $this->backup->save();
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
            $this->backupMethod = $this->backup->backup_method ?? 'dump';
            $this->pgBackRestBackupType = $this->backup->pgbackrest_backup_type ?? 'incr';
            $this->pgBackRestRequireWalArchive = $this->backup->pgbackrest_require_wal_archive ?? true;
            $this->disableLocalBackup = $this->backup->disable_local_backup ?? false;
            $this->s3StorageId = $this->backup->s3_storage_id;
            $this->databasesToBackup = $this->backup->databases_to_backup;
            $this->dumpAll = $this->backup->dump_all;
            $this->timeout = $this->backup->timeout;
        }
    }

    public function delete($password, $selectedActions = [])
    {
        $this->authorize('manageBackups', $this->backup->database);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        try {
            $server = null;
            if ($this->backup->database instanceof ServiceDatabase) {
                $server = $this->backup->database->service->destination->server;
            } elseif ($this->backup->database->destination && $this->backup->database->destination->server) {
                $server = $this->backup->database->destination->server;
            }

            $usesPgBackRest = data_get($this->backup, 'backup_method') === 'pgbackrest';
            if ($usesPgBackRest && $this->delete_associated_backups_s3 && $this->backup->s3) {
                $repositoryPrefix = pgBackRestRepositoryKeyPrefix($this->backup);
                if ($repositoryPrefix) {
                    deleteBackupsS3Prefix($repositoryPrefix, $this->backup->s3);
                }
            }

            $filenames = $this->backup->executions()
                ->whereNotNull('filename')
                ->where('filename', '!=', '')
                ->where('scheduled_database_backup_id', $this->backup->id)
                ->pluck('filename')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($filenames)) {
                if (! $usesPgBackRest && $this->delete_associated_backups_locally && $server) {
                    deleteBackupsLocally($filenames, $server);
                }

                if (! $usesPgBackRest && $this->delete_associated_backups_s3 && $this->backup->s3) {
                    deleteBackupsS3($filenames, $this->backup->s3);
                }
            }

            $this->backup->delete();

            if ($this->backup->database->getMorphClass() === ServiceDatabase::class) {
                $serviceDatabase = $this->backup->database;

                return redirect()->route('project.service.database.backups', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'service_uuid' => $serviceDatabase->service->uuid,
                    'stack_service_uuid' => $serviceDatabase->uuid,
                ]);
            } else {
                return redirect()->route('project.database.backup.index', $this->parameters);
            }
        } catch (Exception $e) {
            $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());

            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('manageBackups', $this->backup->database);

            $this->syncData(true);
            $this->dispatch('success', 'Backup updated successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function updatedBackupMethod(string $value): void
    {
        if ($value === 'pgbackrest') {
            $this->saveS3 = true;
            $this->disableLocalBackup = true;
            $this->dumpAll = true;
            $this->databasesToBackup = null;
            $this->databaseBackupRetentionAmountLocally = 0;
            $this->databaseBackupRetentionDaysLocally = 0;
            $this->databaseBackupRetentionMaxStorageLocally = 0;
            $this->databaseBackupRetentionMaxStorageS3 = 0;
            if (blank($this->s3StorageId) && $this->s3s->count() > 0) {
                $this->s3StorageId = $this->s3s->first()->id;
            }
        }
    }

    public function updatedSaveS3(bool $value): void
    {
        if ($this->backupMethod === 'pgbackrest' && ! $value) {
            $this->saveS3 = true;
        }
    }

    private function customValidate()
    {
        if (! is_numeric($this->backup->s3_storage_id)) {
            $this->backup->s3_storage_id = null;
        }

        if (filled($this->backup->s3_storage_id) && ! $this->selectedS3StorageBelongsToCurrentTeam()) {
            throw new Exception('The selected S3 storage is invalid for this team.');
        }

        // Validate that disable_local_backup can only be true when S3 backup is enabled
        if ($this->backup->disable_local_backup && ! $this->backup->save_s3) {
            $this->backup->disable_local_backup = $this->disableLocalBackup = false;
        }

        if ($this->backup->backup_method === 'pgbackrest') {
            if (! $this->isPostgresDatabase()) {
                throw new Exception('pgBackRest backups are only supported for PostgreSQL databases.');
            }
            if (! $this->backup->save_s3 || blank($this->backup->s3_storage_id)) {
                throw new Exception('pgBackRest backups require S3 storage.');
            }
            $this->backup->disable_local_backup = $this->disableLocalBackup = true;
            $this->backup->dump_all = $this->dumpAll = true;
            $this->backup->databases_to_backup = $this->databasesToBackup = null;
            $this->backup->database_backup_retention_amount_locally = $this->databaseBackupRetentionAmountLocally = 0;
            $this->backup->database_backup_retention_days_locally = $this->databaseBackupRetentionDaysLocally = 0;
            $this->backup->database_backup_retention_max_storage_locally = $this->databaseBackupRetentionMaxStorageLocally = 0;
            $this->backup->database_backup_retention_max_storage_s3 = $this->databaseBackupRetentionMaxStorageS3 = 0;
        }

        $isValid = validate_cron_expression($this->backup->frequency);
        if (! $isValid) {
            throw new Exception('Invalid Cron / Human expression');
        }
        $this->validate();
    }

    public function submit()
    {
        try {
            $this->authorize('manageBackups', $this->backup->database);

            $this->syncData(true);
            $this->dispatch('success', 'Backup updated successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function selectedS3StorageBelongsToCurrentTeam(): bool
    {
        if (blank($this->s3StorageId)) {
            return false;
        }

        return currentTeam()->s3s()->whereKey($this->s3StorageId)->exists();
    }

    private function isPostgresDatabase(): bool
    {
        $databaseType = $this->backup->database instanceof ServiceDatabase
            ? $this->backup->database->databaseType()
            : $this->backup->database->type();

        return str($databaseType)->contains('postgres');
    }

    public function render()
    {
        $checkboxes = [
            ['id' => 'delete_associated_backups_s3', 'label' => $this->backupMethod === 'pgbackrest'
                ? 'The pgBackRest repository for this backup job will be permanently deleted from the selected S3 Storage.'
                : 'All backups will be permanently deleted (associated with this backup job) from the selected S3 Storage.'],
        ];

        if ($this->backupMethod !== 'pgbackrest') {
            array_unshift($checkboxes, ['id' => 'delete_associated_backups_locally', 'label' => __('database.delete_backups_locally')]);
        }

        return view('livewire.project.database.backup-edit', [
            'checkboxes' => $checkboxes,
        ]);
    }
}
