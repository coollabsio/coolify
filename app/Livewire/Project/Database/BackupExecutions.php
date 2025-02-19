<?php

namespace App\Livewire\Project\Database;

use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class BackupExecutions extends Component
{
    public ?ScheduledDatabaseBackup $backup = null;

    public $database;

    public $executions = [];

    public $setDeletableBackup;

    public $delete_backup_s3 = false;

    public $delete_backup_sftp = false;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:team.{$userId},BackupCreated" => 'refreshBackupExecutions',
        ];
    }

    public function cleanupFailed()
    {
        if ($this->backup) {
            $this->backup->executions()->where('status', 'failed')->delete();
            $this->refreshBackupExecutions();
            $this->dispatch('success', 'Failed backups cleaned up.');
        }
    }

    public function deleteBackup($executionId, $password)
    {
        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }

        $execution = $this->backup->executions()->where('id', $executionId)->first();
        if (is_null($execution)) {
            $this->dispatch('error', 'Backup execution not found.');

            return;
        }

        $server = $execution->scheduledDatabaseBackup->database->getMorphClass() === \App\Models\ServiceDatabase::class
            ? $execution->scheduledDatabaseBackup->database->service->destination->server
            : $execution->scheduledDatabaseBackup->database->destination->server;

        try {
            if ($execution->filename) {
                deleteBackupsLocally($execution->filename, $server);

                if ($this->delete_backup_s3 && $execution->scheduledDatabaseBackup->s3) {
                    deleteBackupsS3($execution->filename, $execution->scheduledDatabaseBackup->s3);
                }
            }

            $execution->delete();
            $this->dispatch('success', 'Backup deleted.');
            $this->refreshBackupExecutions();
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());
        }
    }

    public function download_file($exeuctionId)
    {
        return redirect()->route('download.backup', $exeuctionId);
    }

    public function refreshBackupExecutions(): void
    {
        if ($this->backup && $this->backup->exists) {
            $this->executions = $this->backup->executions()->get()->toArray();
        } else {
            $this->executions = [];
        }
    }

    public function mount(ScheduledDatabaseBackup $backup)
    {
        $this->backup = $backup;
        $this->database = $backup->database;
        $this->refreshBackupExecutions();
    }

    public function server()
    {
        if ($this->database) {
            $server = null;

            if ($this->database instanceof \App\Models\ServiceDatabase) {
                $server = $this->database->service->destination->server;
            } elseif ($this->database->destination && $this->database->destination->server) {
                $server = $this->database->destination->server;
            }
            if ($server) {
                return $server;
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.project.database.backup-executions', [
            'checkboxes' => [
                ['id' => 'delete_backup_s3', 'label' => 'Delete the selected backup permanently form S3 Storage'],
                // ['id' => 'delete_backup_sftp', 'label' => 'Delete the selected backup permanently form SFTP Storage'],
            ],
        ]);
    }
}
