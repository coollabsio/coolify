<?php

namespace App\Http\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackupExecution;
use Livewire\Component;

class BackupExecution extends Component
{
    public ScheduledDatabaseBackupExecution $execution;

    public function download()
    {
    }

    public function delete(): void
    {
        delete_backup_locally($this->execution->filename, $this->execution->scheduledDatabaseBackup->database->destination->server);
        $this->execution->delete();
        $this->emit('success', 'Backup execution deleted successfully.');
        $this->emit('refreshBackupExecutions');
    }
}
