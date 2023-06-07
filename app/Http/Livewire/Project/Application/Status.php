<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Status extends Component
{
    public Application $application;

    public function applicationStatusChanged()
    {
        $this->application->refresh();
        $this->emit('applicationStatusChanged');
    }
}
