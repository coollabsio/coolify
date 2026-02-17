<?php

namespace App\Livewire\Project\Database;

use App\Jobs\DatabaseBackupJob;
use App\Jobs\PgBackRestBackupJob;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class BackupNow extends Component
{
    use AuthorizesRequests;

    public $backup;

    public function backupNow()
    {
        $this->authorize('manageBackups', $this->backup->database);

        if ($this->backup->use_pgbackrest) {
            PgBackRestBackupJob::dispatch($this->backup);
            $this->dispatch('success', 'pgBackRest backup queued. It will be available in a few minutes.');
        } else {
            DatabaseBackupJob::dispatch($this->backup);
            $this->dispatch('success', 'Backup queued. It will be available in a few minutes.');
        }
    }
}
