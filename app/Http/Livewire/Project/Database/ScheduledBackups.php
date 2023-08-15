<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;

class ScheduledBackups extends Component
{
    public $database;
    public $parameters;
    protected $listeners = ['refreshScheduledBackups'];

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
    }

    public function delete($scheduled_backup_id): void
    {
        $this->database->scheduledBackups->find($scheduled_backup_id)->delete();
        $this->emit('success', 'Scheduled backup deleted successfully.');
        $this->refreshScheduledBackups();
    }

    public function refreshScheduledBackups(): void
    {
        ray('refreshScheduledBackups');
        $this->database->refresh();
    }
}
