<?php

namespace App\Livewire\Project\Database;

use App\Jobs\DatabaseBackupJob;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class BackupNow extends Component
{
    use AuthorizesRequests;

    public $backup;

    public function backupNow()
    {
        try {
            $this->authorize('manageBackups', $this->backup->database);

            DatabaseBackupJob::dispatch($this->backup);
            $this->dispatch('success', 'Backup queued. It will be available in a few minutes.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
