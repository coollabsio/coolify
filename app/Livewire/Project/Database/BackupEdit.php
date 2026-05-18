<?php

namespace App\Livewire\Project\Database;

use App\Models\InstanceSettings;
use App\Models\PgbackrestRepo;
use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    #[Validate(['required', 'boolean'])]
    public bool $disableLocalBackup = false;

    #[Validate(['nullable', 'integer'])]
    public ?int $s3StorageId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $databasesToBackup = null;

    #[Validate(['required', 'boolean'])]
    public bool $dumpAll = false;

    #[Validate(['required', 'int', 'min:60', 'max:36000'])]
    public int|string $timeout = 3600;

    #[Validate(['required', 'string'])]
    public string $engine = 'native';

    #[Validate(['nullable', 'string', 'in:full,diff,incr'])]
    public ?string $pgbackrestBackupType = 'full';

    #[Validate(['nullable', 'string', 'in:none,bz2,gz,lz4,zst'])]
    public ?string $pgbackrestCompressType = 'lz4';

    #[Validate(['nullable', 'integer', 'min:0', 'max:9'])]
    public ?int $pgbackrestCompressLevel = 6;

    #[Validate(['nullable', 'string', 'in:off,error,warn,info,detail,debug,trace'])]
    public ?string $pgbackrestLogLevel = 'info';

    #[Validate(['nullable', 'string', 'in:standard,reduced,minimal'])]
    public ?string $pgbackrestArchiveMode = 'standard';

    #[Validate(['nullable', 'string', 'in:count,time'])]
    public ?string $localRepoRetentionFullType = 'count';

    #[Validate(['nullable', 'integer', 'min:1'])]
    public ?int $localRepoRetentionFull = 2;

    #[Validate(['nullable', 'integer', 'min:1'])]
    public ?int $localRepoRetentionDiff = 7;

    #[Validate(['nullable', 'integer'])]
    public ?int $s3RepoStorageId = null;

    #[Validate(['nullable', 'string', 'in:count,time'])]
    public ?string $s3RepoRetentionFullType = 'count';

    #[Validate(['nullable', 'integer', 'min:1'])]
    public ?int $s3RepoRetentionFull = 2;

    #[Validate(['nullable', 'integer', 'min:1'])]
    public ?int $s3RepoRetentionDiff = 7;

    public function getShowLocalRepoSettingsProperty(): bool
    {
        return ! $this->disableLocalBackup || ! $this->saveS3;
    }

    /**
     * Toggle between pgBackRest and native backup engines.
     */
    public function togglePgbackrestEngine(): void
    {
        $this->authorize('update', $this->backup->database);

        if (! $this->isPostgresql()) {
            $this->engine = 'native';

            return;
        }

        $this->engine = $this->engine === 'pgbackrest' ? 'native' : 'pgbackrest';
    }

    public function mount()
    {
        try {
            $this->authorize('view', $this->backup->database);
            $this->parameters = get_route_parameters();
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
            $this->backup->engine = $this->engine;

<<<<<<< HEAD
            $this->backup->timeout = $this->timeout;

            if ($this->engine === 'pgbackrest') {
                DB::transaction(function () {
                    $this->backup->save_s3 = $this->saveS3;
                    $computedDisableLocal = $this->saveS3 && $this->disableLocalBackup;
                    $this->disableLocalBackup = $computedDisableLocal;
                    $this->backup->disable_local_backup = $computedDisableLocal;

                    $this->backup->pgbackrest_backup_type = $this->pgbackrestBackupType;
                    $this->backup->pgbackrest_compress_type = $this->pgbackrestCompressType;
                    $this->backup->pgbackrest_compress_level = $this->pgbackrestCompressLevel;
                    $this->backup->pgbackrest_log_level = $this->pgbackrestLogLevel;
                    $this->backup->pgbackrest_archive_mode = $this->pgbackrestArchiveMode;

                    $this->customValidate();
                    $this->backup->save();
                    $this->syncPgbackrestRepos();
                });
            } else {
                $this->backup->save_s3 = $this->saveS3;
                $this->backup->disable_local_backup = $this->disableLocalBackup;
                $this->backup->database_backup_retention_amount_locally = $this->databaseBackupRetentionAmountLocally;
                $this->backup->database_backup_retention_days_locally = $this->databaseBackupRetentionDaysLocally;
                $this->backup->database_backup_retention_max_storage_locally = $this->databaseBackupRetentionMaxStorageLocally;
                $this->backup->database_backup_retention_amount_s3 = $this->databaseBackupRetentionAmountS3;
                $this->backup->database_backup_retention_days_s3 = $this->databaseBackupRetentionDaysS3;
                $this->backup->database_backup_retention_max_storage_s3 = $this->databaseBackupRetentionMaxStorageS3;
                $this->backup->s3_storage_id = $this->s3StorageId;

                if (filled($this->databasesToBackup)) {
                    $databases = str($this->databasesToBackup)->explode(',');
                    foreach ($databases as $index => $db) {
                        $dbName = trim($db);
                        try {
                            validateShellSafePath($dbName, 'database name');
                        } catch (Exception $e) {
                            $position = $index + 1;
                            throw new Exception(
                                "Database #{$position} ('{$dbName}') validation failed: ".
                                $e->getMessage()
                            );
                        }
                    }
                }
=======
            // Validate databases_to_backup to prevent command injection
            // Handles all formats including MongoDB's "db:col1,col2|db2:col3"
            if (filled($this->databasesToBackup)) {
                validateDatabasesBackupInput($this->databasesToBackup);
            }
>>>>>>> origin/next

                $this->backup->databases_to_backup = $this->databasesToBackup;
                $this->backup->dump_all = $this->dumpAll;

                $this->customValidate();
                $this->backup->save();
            }
        } else {
            $this->backupEnabled = $this->backup->enabled;
            $this->frequency = $this->backup->frequency;
            $this->timezone = data_get($this->backup->server(), 'settings.server_timezone', 'Instance timezone');
            $this->engine = $this->backup->engine ?? 'native';

            $this->pgbackrestBackupType = $this->backup->pgbackrest_backup_type ?? 'full';
            $this->pgbackrestCompressType = $this->backup->pgbackrest_compress_type ?? 'lz4';
            $this->pgbackrestCompressLevel = $this->backup->pgbackrest_compress_level ?? 6;
            $this->pgbackrestLogLevel = $this->backup->pgbackrest_log_level ?? 'info';
            $this->pgbackrestArchiveMode = $this->backup->pgbackrest_archive_mode ?? 'standard';

            $this->databaseBackupRetentionAmountLocally = $this->backup->database_backup_retention_amount_locally;
            $this->databaseBackupRetentionDaysLocally = $this->backup->database_backup_retention_days_locally;
            $this->databaseBackupRetentionMaxStorageLocally = $this->backup->database_backup_retention_max_storage_locally;
            $this->databaseBackupRetentionAmountS3 = $this->backup->database_backup_retention_amount_s3;
            $this->databaseBackupRetentionDaysS3 = $this->backup->database_backup_retention_days_s3;
            $this->databaseBackupRetentionMaxStorageS3 = $this->backup->database_backup_retention_max_storage_s3;

            if ($this->engine === 'pgbackrest') {
                $this->loadPgbackrestRepos();
            } else {
                $this->saveS3 = $this->backup->save_s3;
                $this->disableLocalBackup = $this->backup->disable_local_backup ?? false;
            }

            $this->s3StorageId = $this->backup->s3_storage_id;
            $this->databasesToBackup = $this->backup->databases_to_backup;
            $this->dumpAll = $this->backup->dump_all;
            $this->timeout = $this->backup->timeout;
        }
    }

<<<<<<< HEAD
    private function loadPgbackrestRepos(): void
    {
        $localRepo = $this->backup->localRepo();
        $s3Repo = $this->backup->s3Repo();

        $this->disableLocalBackup = $localRepo === null || ! $localRepo->enabled;
        $this->saveS3 = $s3Repo !== null && $s3Repo->enabled;

        if ($localRepo) {
            $this->localRepoRetentionFullType = $localRepo->retention_full_type ?? 'count';
            $this->localRepoRetentionFull = $localRepo->retention_full ?? 2;
            $this->localRepoRetentionDiff = $localRepo->retention_diff ?? 7;
        }

        if ($s3Repo) {
            $this->s3RepoStorageId = $s3Repo->s3_storage_id;
            $this->s3RepoRetentionFullType = $s3Repo->retention_full_type ?? 'count';
            $this->s3RepoRetentionFull = $s3Repo->retention_full ?? 2;
            $this->s3RepoRetentionDiff = $s3Repo->retention_diff ?? 7;
        }
    }

    private function syncPgbackrestRepos(): void
    {
        $hasLocal = ! $this->disableLocalBackup;

        if ($this->saveS3 && empty($this->s3RepoStorageId)) {
            if ($this->s3s->isNotEmpty()) {
                $this->s3RepoStorageId = $this->s3s->first()->id;
            } else {
                throw new Exception('S3 storage must be selected when S3 backups are enabled.');
            }
        }

        $hasS3 = $this->saveS3 && ! empty($this->s3RepoStorageId);

        if (! $hasLocal && ! $hasS3) {
            throw new Exception(
                'At least one backup repository (local or S3) must be enabled for pgBackRest. '.
                'Either enable local backups or configure S3 storage and enable it.'
            );
        }

        $repoNumber = 1;

        if ($hasLocal) {
            $localRepo = $this->backup->localRepo();
            if (! $localRepo) {
                $localRepo = new PgbackrestRepo;
                $localRepo->scheduled_database_backup_id = $this->backup->id;
                $localRepo->type = 'posix';
            }
            $localRepo->repo_number = $repoNumber;
            $localRepo->enabled = true;
            $localRepo->retention_full_type = $this->localRepoRetentionFullType ?? 'count';
            $localRepo->retention_full = $this->localRepoRetentionFull ?? 2;
            $localRepo->retention_diff = $this->localRepoRetentionDiff ?? 7;
            $localRepo->save();
            $repoNumber++;
        } else {
            $this->backup->pgbackrestRepos()->where('type', 'posix')->delete();
        }

        if ($hasS3) {
            $s3Repo = $this->backup->s3Repo();
            if (! $s3Repo) {
                $s3Repo = new PgbackrestRepo;
                $s3Repo->scheduled_database_backup_id = $this->backup->id;
                $s3Repo->type = 's3';
            }
            $s3Repo->repo_number = $repoNumber;
            $s3Repo->enabled = true;
            $s3Repo->s3_storage_id = $this->s3RepoStorageId;
            $s3Repo->retention_full_type = $this->s3RepoRetentionFullType ?? 'count';
            $s3Repo->retention_full = $this->s3RepoRetentionFull ?? 2;
            $s3Repo->retention_diff = $this->s3RepoRetentionDiff ?? 7;
            $s3Repo->save();
        } else {
            $this->backup->pgbackrestRepos()->where('type', 's3')->delete();
        }
    }

    public function delete($password)
    {
        $this->authorize('manageBackups', $this->backup->database);

        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
=======
    public function delete($password, $selectedActions = [])
    {
        $this->authorize('manageBackups', $this->backup->database);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
>>>>>>> origin/next
        }

        try {
            $server = null;
            if ($this->backup->database instanceof \App\Models\ServiceDatabase) {
                $server = $this->backup->database->service->destination->server;
            } elseif ($this->backup->database->destination && $this->backup->database->destination->server) {
                $server = $this->backup->database->destination->server;
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

                if ($this->delete_associated_backups_s3 && $this->backup->s3) {
                    deleteBackupsS3($filenames, $this->backup->s3);
                }
            }

            $this->backup->delete();

            if ($this->backup->database->getMorphClass() === \App\Models\ServiceDatabase::class) {
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

    private function customValidate()
    {
        if (! is_numeric($this->backup->s3_storage_id)) {
            $this->backup->s3_storage_id = null;
        }

        if ($this->backup->disable_local_backup && ! $this->backup->save_s3) {
            $this->backup->disable_local_backup = $this->disableLocalBackup = false;
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

    public function isPostgresql(): bool
    {
        return $this->backup->database_type === StandalonePostgresql::class;
    }

    public function render()
    {
        return view('livewire.project.database.backup-edit', [
            'checkboxes' => [
                ['id' => 'delete_associated_backups_locally', 'label' => __('database.delete_backups_locally')],
                ['id' => 'delete_associated_backups_s3', 'label' => 'All backups will be permanently deleted (associated with this backup job) from the selected S3 Storage.'],
            ],
            'isPostgresql' => $this->isPostgresql(),
        ]);
    }
}
