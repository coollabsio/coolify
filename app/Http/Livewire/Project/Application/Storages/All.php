<?php

namespace App\Http\Livewire\Project\Application\Storages;

use App\Models\Application;
use Livewire\Component;

class All extends Component
{
    public Application $application;
    protected $listeners = ['refreshStorages'];
    public function refreshStorages()
    {
        $this->application->refresh();
    }
}
