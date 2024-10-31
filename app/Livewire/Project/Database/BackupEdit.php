<?php

namespace App\Livewire\Project\Database;

use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Spatie\Url\Url;

class BackupEdit extends Component
{
    public ?ScheduledDatabaseBackup $backup;

    public $s3s;

    public bool $delete_associated_backups_locally = false;

    public bool $delete_associated_backups_s3 = false;

    public bool $delete_associated_backups_sftp = false;

    public ?string $status = null;

    public array $parameters;

    protected $rules = [
        'backup.enabled' => 'required|boolean',
        'backup.frequency' => 'required|string',
        'backup.number_of_backups_locally' => 'required|integer|min:1',
        'backup.save_s3' => 'required|boolean',
        'backup.s3_storage_id' => 'nullable|integer',
        'backup.databases_to_backup' => 'nullable',
        'backup.dump_all' => 'required|boolean',
    ];

    protected $validationAttributes = [
        'backup.enabled' => 'Enabled',
        'backup.frequency' => 'Frequency',
        'backup.number_of_backups_locally' => 'Number of Backups Locally',
        'backup.save_s3' => 'Save to S3',
        'backup.s3_storage_id' => 'S3 Storage',
        'backup.databases_to_backup' => 'Databases to Backup',
        'backup.dump_all' => 'Backup All Databases',
    ];

    protected $messages = [
        'backup.s3_storage_id' => 'Select a S3 Storage',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if (is_null(data_get($this->backup, 's3_storage_id'))) {
            data_set($this->backup, 's3_storage_id', 'default');
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
            $this->custom_validate();
            $this->backup->save();
            $this->backup->refresh();
            $this->dispatch('success', 'Backup updated successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function custom_validate()
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
            $this->custom_validate();
            if ($this->backup->databases_to_backup === '' || $this->backup->databases_to_backup === null) {
                $this->backup->databases_to_backup = null;
            }
            $this->backup->save();
            $this->backup->refresh();
            $this->dispatch('success', 'Backup updated successfully');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function deleteAssociatedBackupsLocally()
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

        if ($backupFolder) {
            $this->deleteEmptyBackupFolder($backupFolder, $server);
        }
    }

    public function deleteAssociatedBackupsS3()
    {
        //Add function to delete backups from S3
    }

    public function deleteAssociatedBackupsSftp()
    {
        //Add function to delete backups from SFTP
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
