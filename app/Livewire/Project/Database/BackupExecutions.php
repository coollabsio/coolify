<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;

class BackupExecutions extends Component
{
    public ?ScheduledDatabaseBackup $backup = null;

    public $executions = [];

    public $setDeletableBackup;

    public function getListeners()
    {
        $userId = auth()->user()->id;

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

    public function deleteBackup($exeuctionId)
    {
        $execution = $this->backup->executions()->where('id', $exeuctionId)->first();
        if (is_null($execution)) {
            $this->dispatch('error', 'Backup execution not found.');

            return;
        }
        if ($execution->scheduledDatabaseBackup->database->getMorphClass() === 'App\Models\ServiceDatabase') {
            delete_backup_locally($execution->filename, $execution->scheduledDatabaseBackup->database->service->destination->server);
        } else {
            delete_backup_locally($execution->filename, $execution->scheduledDatabaseBackup->database->destination->server);
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
}
