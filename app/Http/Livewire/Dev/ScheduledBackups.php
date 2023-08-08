<?php

namespace App\Http\Livewire\Dev;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;

class ScheduledBackups extends Component
{
    public $scheduledDatabaseBackup;

    public function mount()
    {
        $this->scheduledDatabaseBackup = ScheduledDatabaseBackup::all();
    }
}
