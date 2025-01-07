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

    #[Validate(['required', 'integer', 'min:1'])]
    public int $numberOfBackupsLocally = 1;

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
            $this->backup->number_of_backups_locally = $this->numberOfBackupsLocally;
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
            $this->numberOfBackupsLocally = $this->backup->number_of_backups_locally;
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
            if ($this->delete_associated_backups_locally) {
                $this->deleteAssociatedBackupsLocally();
            }
            if ($this->delete_associated_backups_s3) {
                $this->deleteAssociatedBackupsS3();
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
        } catch (\Throwable $e) {
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

    private function deleteAssociatedBackupsLocally()
    {
        $executions = $this->backup->executions;
        $backupFolder = null;

        foreach ($executions as $execution) {
            if ($this->backup->database->getMorphClass() === \App\Models\ServiceDatabase::class) {
                $server = $this->backup->database->service->destination->server;
            } else {
                $server = $this->backup->database->destination->server;
            }

            if (! $backupFolder) {
                $backupFolder = dirname($execution->filename);
            }

            delete_backup_locally($execution->filename, $server);
            $execution->delete();
        }

        if (str($backupFolder)->isNotEmpty()) {
            $this->deleteEmptyBackupFolder($backupFolder, $server);
        }
    }

    private function deleteAssociatedBackupsS3()
    {
        // Add function to delete backups from S3
    }

    private function deleteAssociatedBackupsSftp()
    {
        // Add function to delete backups from SFTP
    }

    private function deleteEmptyBackupFolder($folderPath, $server)
    {
        $checkEmpty = instant_remote_process(["[ -z \"$(ls -A '$folderPath')\" ] && echo 'empty' || echo 'not empty'"], $server);

        if (trim($checkEmpty) === 'empty') {
            instant_remote_process(["rmdir '$folderPath'"], $server);

            $parentFolder = dirname($folderPath);
            $checkParentEmpty = instant_remote_process(["[ -z \"$(ls -A '$parentFolder')\" ] && echo 'empty' || echo 'not empty'"], $server);

            if (trim($checkParentEmpty) === 'empty') {
                instant_remote_process(["rmdir '$parentFolder'"], $server);
            }
        }
    }

    public function render()
    {
        return view('livewire.project.database.backup-edit', [
            'checkboxes' => [
                ['id' => 'delete_associated_backups_locally', 'label' => __('database.delete_backups_locally')],
                // ['id' => 'delete_associated_backups_s3', 'label' => 'All backups associated with this backup job from this database will be permanently deleted from the selected S3 Storage.']
                // ['id' => 'delete_associated_backups_sftp', 'label' => 'All backups associated with this backup job from this database will be permanently deleted from the selected SFTP Storage.']
            ],
        ]);
    }
}
