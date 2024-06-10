<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;

class ScheduledBackups extends Component
{
    public $database;

    public $parameters;

    public $type;

    public ?ScheduledDatabaseBackup $selectedBackup;

    public $selectedBackupId;

    public $s3s;

    protected $listeners = ['refreshScheduledBackups'];

    protected $queryString = ['selectedBackupId'];

    public function mount(): void
    {
        if ($this->selectedBackupId) {
            $this->setSelectedBackup($this->selectedBackupId);
        }
        $this->parameters = get_route_parameters();
        if ($this->database->getMorphClass() === 'App\Models\ServiceDatabase') {
            $this->type = 'service-database';
        } else {
            $this->type = 'database';
        }
        $this->s3s = currentTeam()->s3s;
    }

    public function setSelectedBackup($backupId)
    {
        $this->selectedBackupId = $backupId;
        $this->selectedBackup = $this->database->scheduledBackups->find($this->selectedBackupId);
        if (is_null($this->selectedBackup)) {
            $this->selectedBackupId = null;
        }
    }

    public function delete($scheduled_backup_id): void
    {
        $this->database->scheduledBackups->find($scheduled_backup_id)->delete();
        $this->dispatch('success', 'Scheduled backup deleted.');
        $this->refreshScheduledBackups();
    }

    public function refreshScheduledBackups(?int $id = null): void
    {
        $this->database->refresh();
        if ($id) {
            $this->setSelectedBackup($id);
        }
    }
}
