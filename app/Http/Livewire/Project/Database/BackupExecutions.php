<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;

class BackupExecutions extends Component
{
    public $backup;
    public $executions;
    public $setDeletableBackup;
    protected $listeners = ['refreshBackupExecutions', 'deleteBackup'];

    public function deleteBackup($exeuctionId)
    {
        $execution = $this->backup->executions()->where('id', $exeuctionId)->first();
        if (is_null($execution)) {
            $this->emit('error', 'Backup execution not found.');
            return;
        }
        delete_backup_locally($execution->filename, $execution->scheduledDatabaseBackup->database->destination->server);
        $execution->delete();
        $this->emit('success', 'Backup deleted successfully.');
        $this->emit('refreshBackupExecutions');
    }
    public function refreshBackupExecutions(): void
    {
        $this->executions = $this->backup->executions;
    }
}
