<?php

namespace App\Livewire\Project\Database;

use App\Models\ServiceDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ScheduledBackups extends Component
{
    use AuthorizesRequests;

    public $database;

    public $parameters;

    public $type;

    public string $custom_type = 'mysql';

    protected $listeners = ['refreshScheduledBackups'];

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        if ($this->database->getMorphClass() === ServiceDatabase::class) {
            $this->type = 'service-database';
        } else {
            $this->type = 'database';
        }
    }

    public function setCustomType()
    {
        try {
            $this->authorize('update', $this->database);

            $this->database->custom_type = $this->custom_type;
            $this->database->save();
            $this->dispatch('success', 'Database type set.');
            $this->refreshScheduledBackups();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function delete($scheduled_backup_id): void
    {
        try {
            $this->authorize('manageBackups', $this->database);

            $backup = $this->database->scheduledBackups->find($scheduled_backup_id);
            $backup->delete();
            $this->dispatch('success', 'Scheduled backup deleted.');
            $this->refreshScheduledBackups();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshScheduledBackups(?int $id = null): void
    {
        $this->database->refresh();
        $this->dispatch('refreshScheduledBackups');
    }

    public function render(): View
    {
        $this->database->loadMissing('scheduledBackups.s3');

        return view('livewire.project.database.scheduled-backups');
    }
}
