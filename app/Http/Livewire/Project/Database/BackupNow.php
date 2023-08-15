<?php

namespace App\Http\Livewire\Project\Database;

use App\Jobs\DatabaseBackupJob;
use Livewire\Component;

class BackupNow extends Component
{
    public $backup;
    public function backup_now()
    {
        dispatch(new DatabaseBackupJob(
            backup: $this->backup
        ));
        $this->emit('success', 'Backup queued. It will be available in a few minutes');
    }
}
