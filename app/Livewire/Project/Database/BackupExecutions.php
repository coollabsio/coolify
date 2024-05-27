<?php

namespace App\Livewire\Project\Database;

use Livewire\Component;

class BackupExecutions extends Component
{
    public $backup;
    public $executions = [];
    public $setDeletableBackup;
    public function getListeners()
    {
        $userId = auth()->user()->id;
        return [
            "echo-private:team.{$userId},BackupCreated" => 'refreshBackupExecutions',
            "deleteBackup"
        ];
    }

    public function cleanupFailed()
    {
        $this->backup?->executions()->where('status', 'failed')->delete();
        $this->refreshBackupExecutions();
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
        $this->executions = $this->backup->executions()->get()->sortByDesc('created_at');
    }
}
