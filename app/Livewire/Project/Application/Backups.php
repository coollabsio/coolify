<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Backups extends Component
{
    public Application $application;
    public $databases;

    public function mount()
    {
        $this->databases = $this->application->databases()->get();
    }

    public function render()
    {
        return view('livewire.project.application.backups');
    }
}
