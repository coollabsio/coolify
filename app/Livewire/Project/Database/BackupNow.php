<?php

namespace App\Livewire\Project\Database;

use App\Jobs\DatabaseBackupJob;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class BackupNow extends Component
{
    use AuthorizesRequests;

    public $backup;

    public bool $isRunning = false;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:team.{$userId},BackupCreated" => 'refreshBackupStatus',
        ];
    }

    public function mount()
    {
        $this->refreshBackupStatus();
    }

    public function refreshBackupStatus(): void
    {
        $this->backup->refresh();
        $this->isRunning = $this->backup->hasRunningExecution();
    }

    public function backupNow()
    {
        $this->authorize('manageBackups', $this->backup->database);

        $this->backup->refresh();
        if ($this->backup->hasRunningExecution()) {
            $this->dispatch('error', 'A backup is already running for this schedule. Please wait for it to complete.');
            $this->isRunning = true;

            return;
        }

        DatabaseBackupJob::dispatch($this->backup);
        $this->isRunning = true;
        $this->dispatch('success', 'Backup queued. It will be available in a few minutes.');
    }

    public function render()
    {
        return view('livewire.project.database.backup-now');
    }
}
