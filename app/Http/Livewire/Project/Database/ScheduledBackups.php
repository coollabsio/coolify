<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;

class ScheduledBackups extends Component
{
    public $database;
    protected $listeners = ['refreshScheduledBackups'];

    public function refreshScheduledBackups()
    {
        ray('refreshScheduledBackups');
        $this->database->refresh();
    }
}
