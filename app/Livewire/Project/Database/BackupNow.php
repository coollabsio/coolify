<?php

namespace App\Livewire\Project\Database;

use App\Jobs\DatabaseBackupJob;
use Livewire\Component;

class BackupNow extends Component
{
    public $backup;

    public function backupNow()
    {
        DatabaseBackupJob::dispatch($this->backup);
        $this->dispatch('success', 'Backup queued. It will be available in a few minutes.');
    }
}
