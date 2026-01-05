<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ScheduledBackups extends Component
{
    use AuthorizesRequests;

    public $database;

    public $parameters;

    public $type;

    public ?ScheduledDatabaseBackup $selectedBackup;

    public $selectedBackupId;

    public $s3s;

    public string $custom_type = 'mysql';

    protected $listeners = ['refreshScheduledBackups'];

    protected $queryString = ['selectedBackupId'];

    public function mount(): void
    {
        if ($this->selectedBackupId) {
            $this->setSelectedBackup($this->selectedBackupId, true);
        }
        $this->parameters = get_route_parameters();
        if ($this->database->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $this->type = 'service-database';
        } else {
            $this->type = 'database';
        }
        $this->s3s = currentTeam()->s3s;
    }

    public function setSelectedBackup($backupId, $force = false)
    {
        if ($this->selectedBackupId === $backupId && ! $force) {
            return;
        }
        $this->selectedBackupId = $backupId;
        $this->selectedBackup = $this->database->scheduledBackups->find($backupId);
        if (is_null($this->selectedBackup)) {
            $this->selectedBackupId = null;
        }
    }

    public function setCustomType()
    {
        $this->authorize('update', $this->database);

        $this->database->custom_type = $this->custom_type;
        $this->database->save();
        $this->dispatch('success', 'Database type set.');
        $this->refreshScheduledBackups();
    }

    public function delete($scheduled_backup_id): void
    {
        $backup = $this->database->scheduledBackups->find($scheduled_backup_id);
        $this->authorize('manageBackups', $this->database);

        $backup->delete();
        $this->dispatch('success', 'Scheduled backup deleted.');
        $this->refreshScheduledBackups();
    }

    public function refreshScheduledBackups(?int $id = null): void
    {
        $this->database->refresh();
        if ($id) {
            $this->setSelectedBackup($id);
        }
        $this->dispatch('refreshScheduledBackups');
    }
}
