<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class BackupExecutions extends Component
{
    public ?ScheduledDatabaseBackup $backup = null;

    public $executions = [];

    public $setDeletableBackup;

    public $delete_backup_s3 = true;
    public $delete_backup_sftp = true;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:team.{$userId},BackupCreated" => 'refreshBackupExecutions',
            'deleteBackup',
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

    #[On('deleteBackup')]
    public function deleteBackup($executionId, $password)
    {
        if (!Hash::check($password, Auth::user()->password)) {
            $this->addError('password', 'The provided password is incorrect.');
            return;
        }

        $execution = $this->backup->executions()->where('id', $executionId)->first();
        if (is_null($execution)) {
            $this->dispatch('error', 'Backup execution not found.');
            return;
        }

        if ($execution->scheduledDatabaseBackup->database->getMorphClass() === 'App\Models\ServiceDatabase') {
            delete_backup_locally($execution->filename, $execution->scheduledDatabaseBackup->database->service->destination->server);
        } else {
            delete_backup_locally($execution->filename, $execution->scheduledDatabaseBackup->database->destination->server);
        }

        if ($this->delete_backup_s3) {
            // Add logic to delete from S3
        }

        if ($this->delete_backup_sftp) {
            // Add logic to delete from SFTP
        }

        $execution->delete();
        $this->dispatch('success', 'Backup deleted.');
        $this->refreshBackupExecutions();
    }

    public function download_file($exeuctionId)
    {
        return redirect()->route('download.backup', $exeuctionId);
    }

    public function refreshBackupExecutions(): void
    {
        if ($this->backup) {
            $this->executions = $this->backup->executions()->get()->sortBy('created_at');
        }
    }

    public function render()
    {
        return view('livewire.project.database.backup-executions', [
            'checkboxes' => [
                ['id' => 'delete_backup_s3', 'label' => 'Delete the selected backup permanently form S3 Storage'],
                ['id' => 'delete_backup_sftp', 'label' => 'Delete the selected backup permanently form SFTP Storage'],
            ]
        ]);
    }
}
