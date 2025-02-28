<?php

namespace App\Livewire\Project\Database;

use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Url\Url;

class BackupEdit extends Component
{
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

    #[Validate(['nullable', 'integer'])]
    public ?int $s3StorageId = 1;

    #[Validate(['nullable', 'string'])]
    public ?string $databasesToBackup = null;

    #[Validate(['required', 'boolean'])]
    public bool $dumpAll = false;

    public function mount()
    {
        try {
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
            $this->backup->database_backup_retention_amount_locally = $this->databaseBackupRetentionAmountLocally;
            $this->backup->database_backup_retention_days_locally = $this->databaseBackupRetentionDaysLocally;
            $this->backup->database_backup_retention_max_storage_locally = $this->databaseBackupRetentionMaxStorageLocally;
            $this->backup->database_backup_retention_amount_s3 = $this->databaseBackupRetentionAmountS3;
            $this->backup->database_backup_retention_days_s3 = $this->databaseBackupRetentionDaysS3;
            $this->backup->database_backup_retention_max_storage_s3 = $this->databaseBackupRetentionMaxStorageS3;
            $this->backup->save_s3 = $this->saveS3;
            $this->backup->s3_storage_id = $this->s3StorageId;
            $this->backup->databases_to_backup = $this->databasesToBackup;
            $this->backup->dump_all = $this->dumpAll;
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
            $this->s3StorageId = $this->backup->s3_storage_id;
            $this->databasesToBackup = $this->backup->databases_to_backup;
            $this->dumpAll = $this->backup->dump_all;
        }
    }

    public function delete($password)
    {
        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
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
                $previousUrl = url()->previous();
                $url = Url::fromString($previousUrl);
                $url = $url->withoutQueryParameter('selectedBackupId');
                $url = $url->withFragment('backups');
                $url = $url->getPath()."#{$url->getFragment()}";

                return redirect($url);
            } else {
                return redirect()->route('project.database.backup.index', $this->parameters);
            }
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());

            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
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
        $isValid = validate_cron_expression($this->backup->frequency);
        if (! $isValid) {
            throw new \Exception('Invalid Cron / Human expression');
        }
        $this->validate();
    }

    public function submit()
    {
        try {
            $this->syncData(true);
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
                ['id' => 'delete_associated_backups_s3', 'label' => 'All backups will be permanently deleted (associated with this backup job) from the selected S3 Storage.'],
                // ['id' => 'delete_associated_backups_sftp', 'label' => 'All backups associated with this backup job from this database will be permanently deleted from the selected SFTP Storage.']
            ],
        ]);
    }
}
